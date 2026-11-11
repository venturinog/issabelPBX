<?php
if (!defined('ISSABELPBX_IS_AUTH')) { die('No direct script access allowed'); }
//    License for all code of this IssabelPBX module can be found in the license file inside the module directory
//    Copyright 2013 Schmooze Com Inc.
//    Copyright 2022 Issabel Foundation


// The destinations this module provides
// returns a associative arrays with keys 'destination' and 'description'
function ivr_destinations() {
    global $module_page;

    //get the list of IVR's
    $results = ivr_get_details();

    // return an associative array with destination and description
    if (isset($results)) {
        foreach($results as $result){
            $name = $result['name'] ? $result['name'] : 'IVR ' . $result['id'];
            $extens[] = array('destination' => 'ivr-'.$result['id'].',s,1', 'description' => $name);
        }
    }
    if (isset($extens)) {
        return $extens;
    } else {
        return null;
    }

}

/**
 * Add the TTS columns to ivr_details when the module upgrade could not do it.
 * Called from the page load and from the save path so an old database heals
 * itself without waiting for a package reinstall.
 */
function ivr_check_alter_table() {
    global $db, $amp_conf;

    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $sql = "SELECT tts_lang FROM ivr_details";
    $check = $db->getRow($sql, DB_FETCHMODE_ASSOC);
    if (DB::IsError($check)) {
        $sql = "ALTER TABLE ivr_details ADD tts_lang VARCHAR(10) NOT NULL DEFAULT 'en-US'";
        $result = $db->query($sql);
        if (DB::IsError($result)) {
            error_log('ivr: could not add tts_lang column: ' . $result->getMessage());
        }
    }

    $sql = "SELECT tts_text FROM ivr_details";
    $check = $db->getRow($sql, DB_FETCHMODE_ASSOC);
    if (DB::IsError($check)) {
        // MariaDB 10.2+ requires an expression default for TEXT columns.
        $default = preg_match('/qlite/', isset($amp_conf['AMPDBENGINE']) ? $amp_conf['AMPDBENGINE'] : '') ? "''" : "('')";
        $sql = "ALTER TABLE ivr_details ADD tts_text TEXT NOT NULL DEFAULT " . $default;
        $result = $db->query($sql);
        if (DB::IsError($result)) {
            error_log('ivr: could not add tts_text column: ' . $result->getMessage());
        }
    }
}

/**
 * Synthesize a TTS prompt with the shared Piper engine.
 *
 * Returns the absolute path of the generated file, or an empty string when
 * TTS is not available or the synthesis failed. The file lives in
 * <ASTDATADIR>/sounds/custom/tts so it is included in the regular backups.
 */
function ivr_tts_prompt_file($text, $lang = '') {
    if (!function_exists('issabel_tts_agi_path') || !function_exists('issabel_tts_enabled') || !issabel_tts_enabled()) {
        return '';
    }

    $text = function_exists('issabel_tts_normalize_text') ? issabel_tts_normalize_text($text) : trim((string)$text);
    if ($text === '') {
        return '';
    }

    if (function_exists('issabel_tts_normalize_lang')) {
        $fallback = function_exists('issabel_tts_default_lang') ? issabel_tts_default_lang() : 'en-US';
        $lang = issabel_tts_normalize_lang($lang, $fallback);
    }
    $params = function_exists('issabel_tts_merge_params') ? issabel_tts_merge_params(array(), $lang) : '';
    if ($params === '') {
        $params = json_encode($lang !== '' ? array('lang' => $lang) : array());
    }

    $class = dirname(issabel_tts_agi_path()) . '/issabel-tts/issabelTtsEngine.class.php';
    if (!is_file($class)) {
        error_log('ivr: TTS engine class not found at ' . $class);
        return '';
    }
    require_once $class;
    if (!class_exists('issabelTtsEngine')) {
        return '';
    }

    try {
        $engine = new issabelTtsEngine(null);
        $files = $engine->synthesize($text, array(
            'engine'     => 'piper',
            'cmd'        => '/usr/bin/piper',
            'params_raw' => $params,
            'gui'        => 1,
        ));
        if (!empty($files[0]) && is_file($files[0])) {
            @chmod($files[0], 0644);
            return $files[0];
        }
    } catch (Throwable $e) {
        error_log('ivr: TTS generation failed: ' . $e->getMessage());
    }

    return '';
}

/**
 * Return the TTS prompt as a filename relative to the Asterisk sounds
 * directory (without extension), which is what Background() expects.
 */
function ivr_tts_prompt_filename($text, $lang = '') {
    global $amp_conf;

    $file = ivr_tts_prompt_file($text, $lang);
    if ($file === '') {
        return '';
    }

    $astdatadir = !empty($amp_conf['ASTDATADIR']) ? $amp_conf['ASTDATADIR'] : '/var/lib/asterisk';
    $sounds = rtrim($astdatadir, '/') . '/sounds/';
    if (strpos($file, $sounds) !== 0) {
        // Fall back to the standard marker when amportal.conf is not loaded.
        $marker = '/sounds/';
        $pos = strpos($file, $marker);
        if ($pos === false) {
            error_log('ivr: TTS file is outside the sounds directory: ' . $file);
            return '';
        }
        $file = substr($file, $pos + strlen($marker));
    } else {
        $file = substr($file, strlen($sounds));
    }

    return preg_replace('/\.[^.]+$/', '', $file);
}

//dialplan generator
function ivr_get_config($engine) {
    global $ext, $amp_conf, $astman;

    $show_spoken=0;
    $engineinfo = engine_getinfo();
    $astver =  $engineinfo['version'];
    $ast_ge_1616 = version_compare($astver, '16.16', 'ge');
    if(file_exists("/etc/asterisk/res-speech-vosk.conf") && $ast_ge_1616) {
        $show_spoken=1;
    }
    $rt = $astman->send_request("Command", array("Command" => "aeap show clients"));
    if($rt['Response']=='Success') { $show_spoken=1; }

    switch($engine) {
        case "asterisk":
            $ddial_contexts = array();
            $ivrlist = ivr_get_details();
            if(!is_array($ivrlist)) {
                break;
            }

            if (function_exists('queues_list')) {
                //draw a list of ivrs included by any queues
                $queues = queues_list(true);
                $qivr = array();
                foreach ($queues as $q) {
                    $thisq = queues_get($q[0]);
                    if ($thisq['context'] && strpos($thisq['context'], 'ivr-') === 0) {
                        $qivr[] = str_replace('ivr-', '', $thisq['context']);
                    }
                }
            }

            foreach($ivrlist as $ivr) {
                $c = 'ivr-' . $ivr['id'];
                $ivr = ivr_get_details($ivr['id']);
                $ext->addSectionComment($c, $ivr['name'] ? $ivr['name'] : 'IVR ' . $ivr['id']);

                $ext->addInclude($c, 'ivr-global-custom');

                if ($ivr['directdial']) {
                    if ($ivr['directdial'] == 'ext-local') {
                        $ext->addInclude($c, 'from-did-direct-ivr'); //generated in core module
                    } else {
                        //generated by directory
                        $ext->addInclude($c, 'from-ivr-directory-' . $ivr['directdial']);
                        $directdial_contexts[$ivr['directdial']] = $ivr['directdial'];
                    }
                }

                //set variables for loops when used
                if ($ivr['timeout_loops'] != 'disabled' && $ivr['timeout_loops'] > 0) {
                    $ext->add($c, 's', '', new ext_setvar('TIMEOUT_LOOPCOUNT', 0));
                }
                if ($ivr['invalid_loops'] != 'disabled' && $ivr['invalid_loops'] > 0) {
                    $ext->add($c, 's', '', new ext_setvar('INVALID_LOOPCOUNT', 0));
                }

                $ext->add($c, 's', '', new ext_setvar('_IVR_CONTEXT_${CONTEXT}', '${IVR_CONTEXT}'));
                $ext->add($c, 's', '', new ext_setvar('_IVR_CONTEXT', '${CONTEXT}'));
                if ($ivr['retvm']) {
                    $ext->add($c, 's', '', new ext_setvar('__IVR_RETVM', 'RETURN'));
                } else {
                    //TODO: do we need to set anything at all?
                    $ext->add($c, 's', '', new ext_setvar('__IVR_RETVM', ''));
                }

                $ext->add($c, 's', '', new ext_gotoif('$["${CDR(disposition)}" = "ANSWERED"]','skip'));
                $ext->add($c, 's', '', new ext_gotoif('$["${CDR(answer)}" != ""]','skip'));
                $ext->add($c, 's', '', new ext_answer(''));
                $ext->add($c, 's', '', new ext_wait('1'));

                $ivr_announcement = recordings_get_file($ivr['announcement']);
                if (empty($ivr['announcement']) && isset($ivr['tts_text']) && trim((string)$ivr['tts_text']) !== '') {
                    $tts_prompt = ivr_tts_prompt_filename($ivr['tts_text'], isset($ivr['tts_lang']) ? $ivr['tts_lang'] : '');
                    if ($tts_prompt !== '') {
                        $ivr_announcement = $tts_prompt;
                    }
                }
                $ext->add($c, 's', 'skip', new ext_set('IVR_MSG', $ivr_announcement));

                $ext->add($c, 's', 'start', new ext_digittimeout(3));
                //$ext->add($ivr_id, 's', '', new ext_responsetimeout($ivr['timeout_time']));

                $entries = ivr_get_entries($ivr['id']);

                $do_spoken_dialplan=0;

                if ($entries) {
                    if($show_spoken==1) {
                        foreach($entries as $e) {
                            if($e['spoken']!='') {
                                $do_spoken_dialplan=1;
                            }
                        }
                    }
                }

                if($do_spoken_dialplan==0) {
                    $ext->add($c, 's', '', new ext_execif('$["${IVR_MSG}" != ""]','Background','${IVR_MSG}'));
                    $ext->add($c, 's', '', new ext_waitexten($ivr['timeout_time']));
                } else {
                    $ext->add($c, 's', '', new ext_speechcreate());

                    // Grammar
                    if(isset($amp_conf["IVR_USE_VOSK_GRAMMAR"]) && $amp_conf["IVR_USE_VOSK_GRAMMAR"]==1) {
                        $allwords=array();
                        if ($entries) {
                            foreach($entries as $e) {
                                if($e['spoken']!='') {
                                    $allwords = array_merge($allwords,preg_split("/,/",$e['spoken']));
                                }
                            }
                            $word_list = '"'.implode('","',$allwords).'"';
                            $ext->add($c, 's', '', new ext_speechactivategrammar($word_list));
                        }
                    }

                    $ext->add($c, 's', '', new ext_gotoif('$["${ERROR}" = "1"]','skipspeech'));
                    $ext->add($c, 's', '', new ext_execif('$["${IVR_MSG}" != ""]','SpeechBackground','${IVR_MSG},'.$ivr['timeout_time']));
                    $ext->add($c, 's', '', new ext_execif('$["${IVR_MSG}X" = "X"]','SpeechBackground','silence/5,'.$ivr['timeout_time']));
                    $ext->add($c, 's', '', new ext_setvar('RESULT', '${SPEECH_TEXT(0)}'));

                }

                // Actually add the IVR entires now
                if ($entries) {
                    // pass for spoken text
                    if($do_spoken_dialplan==1) {
                        foreach($entries as $e) {
                            if($e['spoken']!='') {
                                $words = preg_split("/,/",$e['spoken']);
                                foreach($words as $word) {
                                    $ext->add($c, 's', '', new ext_execif('$["${RESULT}" =~ "'.$word.'"]','Set','RESULT='.$e['selection']));
                                }
                            }
                        }
                        $ext->add($c, 's', '', new ext_execif('$["${RESULT}x" = "x"]','Set','RESULT=t'));
                        $ext->add($c, 's', '', new ext_goto('${RESULT},1'));
                        $ext->add($c, 's', 'skipspeech', new ext_execif('$["${IVR_MSG}" != ""]','Background','${IVR_MSG}'));
                        $ext->add($c, 's', '', new ext_waitexten($ivr['timeout_time']));
                    }

                    foreach($entries as $e) {
                        //dont set a t or i if there already defined above
                        if ($e['selection'] == 't' && $ivr['timeout_loops'] != 'disabled') {
                             continue;
                        }
                        if ($e['selection'] == 'i' && $ivr['invalid_loops'] != 'disabled') {
                             continue;
                        }

                        //only display these two lines if the ivr is included in any queues
                        if (function_exists('queues_list') && in_array($ivr['id'], $qivr)) {
                            $ext->add($c, $e['selection'],'', new ext_gosub('1','s','sub-blkvm-clr'));
                            $ext->add($c, $e['selection'], '', new ext_setvar('__NODEST', ''));
                        }

                        if ($e['ivr_ret']) {
                            $ext->add($c, $e['selection'], '',
                                new ext_gotoif('$["x${IVR_CONTEXT_${CONTEXT}}" = "x"]',
                                    $e['dest'] . ':${IVR_CONTEXT_${CONTEXT}},return,1'));
                        } else {
                            $ext->add($c, $e['selection'], '', new ext_setvar('__IVR_DIGIT_PRESSED', '${EXTEN}'));
                            $ext->add($c, $e['selection'],'ivrsel-' . $e['selection'], new ext_goto($e['dest']));
                        }
                    }
                }

                // add invalid destination if required
                if ($ivr['invalid_loops'] != 'disabled') {
                    if ($ivr['invalid_loops'] > 0) {
                        $ext->add($c, 'i', '', new ext_set('INVALID_LOOPCOUNT', '$[${INVALID_LOOPCOUNT}+1]'));
                        $ext->add($c, 'i', '',    new ext_gotoif('$[${INVALID_LOOPCOUNT} > ' . $ivr['invalid_loops'] . ']','final'));
                        switch ($ivr['invalid_retry_recording']) {
                            case 'default':
                                $invalid_annoucement = 'no-valid-responce-pls-try-again';
                                break;
                            case '':
                                $invalid_annoucement = '';
                                break;
                            default:
                                $invalid_annoucement = recordings_get_file($ivr['invalid_retry_recording']);
                                break;
                        }

                        if ($ivr['invalid_append_announce'] || $invalid_annoucement == '') {
                            $invalid_annoucement .= '&' . $ivr_announcement;
                        }
                        $ext->add($c, 'i', '', new ext_set('IVR_MSG', trim($invalid_annoucement, '&')));
                        $ext->add($c, 'i', '', new ext_goto('s,start'));
                    }

                    $label = 'final';
                    switch ($ivr['invalid_recording']) {
                        case 'default':
                            $ext->add($c, 'i', $label, new ext_playback('no-valid-responce-transfering'));
                            $label ='';
                            break;
                        case '':
                            break;
                        default:
                            $ext->add($c, 'i', $label,
                                new ext_playback(recordings_get_file($ivr['invalid_recording'])));
                            $label = '';
                            break;
                    }
                    if (!empty($ivr['invalid_ivr_ret'])) {
                        $ext->add($c, 'i', $label,
                            new ext_gotoif('$["x${IVR_CONTEXT_${CONTEXT}}" = "x"]',
                                $ivr['invalid_destination'] . ':${IVR_CONTEXT_${CONTEXT}},return,1'));
                    } else {
                        $ext->add($c, 'i', $label, new ext_goto($ivr['invalid_destination']));
                    }
                } else {
                    // If no invalid destination provided we need to do something
                    $ext->add($c, 'i', '', new ext_playback('sorry-youre-having-problems'));
                    $ext->add($c, 'i', '', new ext_goto('1','hang'));
                }

                // Apply timeout destination if required
                if ($ivr['timeout_loops'] != 'disabled') {
                    if ($ivr['timeout_loops'] > 0) {
                        $ext->add($c, 't', '', new ext_set('TIMEOUT_LOOPCOUNT', '$[${TIMEOUT_LOOPCOUNT}+1]'));
                        $ext->add($c, 't', '', new ext_gotoif('$[${TIMEOUT_LOOPCOUNT} > ' . $ivr['timeout_loops'] . ']','final'));

                        switch ($ivr['timeout_retry_recording']) {
                            case 'default':
                                $timeout_annoucement = 'no-valid-responce-pls-try-again';
                                break;
                            case '':
                                $timeout_annoucement = '';
                                break;
                            default:
                                $timeout_annoucement = recordings_get_file($ivr['timeout_retry_recording']);
                                break;
                        }

                        if ($ivr['timeout_append_announce'] || $timeout_annoucement == '') {
                            $timeout_annoucement .= '&' . $ivr_announcement;
                        }
                        $ext->add($c, 't', '', new ext_set('IVR_MSG', trim($timeout_annoucement, '&')));
                        $ext->add($c, 't', '', new ext_goto('s,start'));
                    }

                    $label = 'final';
                    switch ($ivr['timeout_recording']) {
                        case 'default':
                            $ext->add($c, 't', $label, new ext_playback('no-valid-responce-transfering'));
                            $label = '';
                            break;
                        case '':
                            break;
                        default:
                            $ext->add($c, 't', $label,
                                new ext_playback(recordings_get_file($ivr['timeout_recording'])));
                            $label = '';
                            break;
                    }
                    if (!empty($ivr['timeout_ivr_ret'])) {
                        $ext->add($c, 't', $label,
                            new ext_gotoif('$["x${IVR_CONTEXT_${CONTEXT}}" = "x"]',
                                $ivr['timeout_destination'] . ':${IVR_CONTEXT_${CONTEXT}},return,1'));
                    } else {
                        $ext->add($c, 't', $label, new ext_goto($ivr['timeout_destination']));
                    }
                } else {
                    // If no invalid destination provided we need to do something
                    $ext->add($c, 't', '', new ext_playback('sorry-youre-having-problems'));
                    $ext->add($c, 't', '', new ext_goto('1','hang'));
                }

                // these need to be reset or inheritance problems makes them go away in some conditions
                //and infinite inheritance creates other problems
                $ext->add($c, 'return', '', new ext_setvar('_IVR_CONTEXT', '${CONTEXT}'));
                $ext->add($c, 'return', '', new ext_setvar('_IVR_CONTEXT_${CONTEXT}', '${IVR_CONTEXT_${CONTEXT}}'));
                $ext->add($c, 'return', '', new ext_set('IVR_MSG', $ivr_announcement));
                $ext->add($c, 'return', '', new ext_goto('s,start'));

                //h extension
                $ext->add($c, 'h', '', new ext_hangup(''));
                $ext->add($c, 'hang', '', new ext_playback('vm-goodbye'));
                $ext->add($c, 'hang', '', new ext_hangup(''));
            }


            //generate from-ivr-directory contexts for direct dialing a directory entire
            if (!empty($directdial_contexts)) {
                foreach($directdial_contexts as $dir_id) {
                    $c = 'from-ivr-directory-' . $dir_id;
                    $entries = function_exists('directory_get_dir_entries') ? directory_get_dir_entries($dir_id) : array();
                    foreach ($entries as $dstring) {
                        $exten = $dstring['dial'] == '' ? $dstring['foreign_id'] : $dstring['dial'];
                        if ($exten == '' || $exten == 'custom') {
                            continue;
                        }
                        $ext->add($c, $exten, '', new ext_gosub('1','s','sub-blkvm-clr'));
                        $ext->add($c, $exten, '', new ext_setvar('__NODEST', ''));
                        $ext->add($c, $exten, '', new ext_goto('1', $exten, 'from-internal'));
                    }
                }
            }
        break;
    }
}

//replaces ivr_list(), returns all details of any ivr
function ivr_get_details($id = '') {
    global $db;

    ivr_check_alter_table();

    $sql = "SELECT `spoken` FROM ivr_entries";
    $check = $db->getRow($sql, DB_FETCHMODE_ASSOC);
    if(DB::IsError($check)) {
        $sql = "ALTER TABLE ivr_entries ADD spoken varchar(200) not null default ''";
        $result = $db->query($sql);
    }

    $sql = "SELECT *, announcement announcement_id FROM ivr_details";
    if ($id) {
        $sql .= ' where  id = "' . $id . '"';
    } else {
        // Corrado Mella, 06/02/2014 - IVR list in alphabetical order
        $sql .= ' ORDER BY name';
        // Corrado Mella
    }

    $res = $db->getAll($sql, DB_FETCHMODE_ASSOC);
    if($db->IsError($res)) {
        die_issabelpbx($res->getDebugInfo());
    }
    return $id && isset($res[0]) ? $res[0] : $res;
}

//get all ivr entires
function ivr_get_entries($id) {
    global $db;

    //+0 to convert string to an integer
    $sql = "SELECT * FROM ivr_entries WHERE ivr_id = ? ORDER BY selection + 0 DESC";
    $res = $db->getAll($sql, array($id), DB_FETCHMODE_ASSOC);
    if ($db->IsError($res)) {
        die_issabelpbx($res->getDebugInfo());
    }
    return $res;
}


//draw ivr options page
function ivr_configpageload() {
    global $currentcomponent, $display;
    $action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';
    $id     = isset($_REQUEST['extdisplay']) ? $_REQUEST['extdisplay'] : null;

    $helptext = __("Creates Digital Receptionist (aka Auto-Attendant, aka Interactive Voice Response) menus. These can be used to send callers to different locations (eg, Press 1 for sales) and/or allow direct-dialing of extension numbers.");
    $help = '<div class="infohelp">?<span style="display:none;">'.$helptext.'</span></div>';

    if ($action  == 'add' || ($action == '' && $id == null)) {
        $currentcomponent->addguielem('_top', new gui_pageheading('title', __('Add IVR'),true, $help), 0);

        $deet = array('id', 'name', 'description', 'announcement', 'directdial',
                    'invalid_loops', 'invalid_retry_recording',
                    'invalid_recording', 'invalid_destination', 'invalid_ivr_ret',
                    'timeout_loops', 'timeout_time', 'timeout_retry_recording',
                    'timeout_recording', 'timeout_destination', 'timeout_ivr_ret',
                    'retvm', 'tts_text', 'tts_lang');

        //keep vairables set on new ivr's
        foreach ($deet as $d) {
            switch ($d){
                case 'invalid_loops':
                case 'timeout_loops';
                    $ivr[$d] = 3;
                    break;
                case 'announcement':
                    $ivr[$d] = '';
                    break;
                case 'invalid_recording':
                case 'invalid_retry_recording':
                case 'timeout_retry_recording':
                case 'timeout_recording':
                    $ivr[$d] = 'default';
                    break;
                case 'timeout_time':
                    $ivr[$d] = 10;
                    break;
                default:
                $ivr[$d] = '';
                    break;
            }
        }
    } else {
        $ivr = ivr_get_details($id);
        if(count($ivr)==0) {

            $ivr = array(
                'name'=>'',
                'description'=>'',
                'invalid_ivr_ret'=>0,
                'invalid_recording'=>0,
                'invalid_destination'=>0,
                'timeout_loops'=>3,
                'timeout_retry_recording'=>'default',
                'invalid_retry_recording'=>0,
                'timeout_ivr_ret'=>10,
                'timeout_destination'=>0,
                'timeout_recording'=>0,
                'retvm'=>0,
                'announcement'=>0,
                'directdial'=>0,
                'timeout_time'=>10,
                'invalid_loops'=>3,
                'tts_text'=>'',
                'tts_lang'=>'',
                'id'=>''
            );

        } else if(count($ivr)==1) {
            $ivr = $ivr[0];
        }
        $label = sprintf(__("Edit IVR: %s"), $ivr['name'] ? $ivr['name'] : 'ID '.$ivr['id']);
        $currentcomponent->addguielem('_top', new gui_pageheading('title', $label, true, $help), 0);

        //display usage
        $usage_list            = framework_display_destination_usage(ivr_getdest($ivr['id']));
        if (!empty($usage_list)) {
            $usage_list_text    = isset($usage_list['text']) ? $usage_list['text'] : '';
            $usage_list_tooltip    = isset($usage_list['tooltip']) ? $usage_list['tooltip'] : '';
            $currentcomponent->addguielem('_top',
                new gui_link_label('usage', $usage_list_text, $usage_list_tooltip), 0);
        }
    }

    //general options
    $gen_section = __('IVR General Options');
    $currentcomponent->addguielem($gen_section,
        new gui_textbox('name', stripslashes($ivr['name']), __('IVR Name'), __('Name of this IVR.')));
    $currentcomponent->addguielem($gen_section,
        new gui_textbox('description', stripslashes($ivr['description']),
        __('IVR Description'), __('Description of this ivr.')));


    //dtmf options
    $section = __('IVR Options (DTMF)');

    //build recordings select list
    $currentcomponent->addoptlistitem('recordings', '', __('None'));
    foreach(recordings_list() as $r){
        $currentcomponent->addoptlistitem('recordings', $r['id'], $r['displayname']);
    }
    $currentcomponent->setoptlistopts('recordings', 'sort', false);

    //build repeat_loops select list and defualt it to 3
    //while addoptlist is not usually required, declaring this is the only way to prevent sorting on the list
    $currentcomponent->addoptlist('ivr_repeat_loops', false);
    $currentcomponent->addoptlistitem('ivr_repeat_loops', 'disabled', __('Disabled'));
    for($i=0; $i <11; $i++){
        $currentcomponent->addoptlistitem('ivr_repeat_loops', $i, $i);
    }

    // Text to Speech greeting. It is only used when no recording is selected.
    $tts_options = (function_exists('issabel_tts_enabled') && issabel_tts_enabled() && function_exists('issabel_tts_language_options'))
        ? issabel_tts_language_options() : array();

    //greating to be played on entry to the ivr
    $currentcomponent->addguielem($section,
        new gui_selectbox('announcement', $currentcomponent->getoptlist('recordings'),
            $ivr['announcement'], __('Announcement'), __('Greeting to be played on entry to the Ivr.'), false,
            !empty($tts_options) ? 'ivrToggleTts();' : ''));

    if (!empty($tts_options)) {
        $tts_lang = isset($ivr['tts_lang']) ? (string)$ivr['tts_lang'] : '';
        if ($tts_lang === '' || !isset($tts_options[$tts_lang])) {
            $tts_lang = function_exists('issabel_tts_default_lang') ? issabel_tts_default_lang() : 'en-US';
        }
        if (!isset($tts_options[$tts_lang])) {
            $tts_codes = array_keys($tts_options);
            $tts_lang = $tts_codes[0];
        }
        $tts_text = isset($ivr['tts_text']) ? (string)$ivr['tts_text'] : '';

        $currentcomponent->addoptlist('tts_langs', false);
        foreach ($tts_options as $code => $label) {
            $currentcomponent->addoptlistitem('tts_langs', $code, $label);
        }

        $currentcomponent->addguielem($section,
            new guielement('tts_heading', '<tr><td colspan="2"><br><h5>' . __('Text to Speech') . '</h5></td></tr>'));

        $currentcomponent->addguielem($section,
            new gui_selectbox('tts_lang', $currentcomponent->getoptlist('tts_langs'),
                $tts_lang, __('Language'), __('Language used for the text to speech greeting.'), false));

        // Same width as the select boxes and at least five visible lines.
        $currentcomponent->addguielem($section,
            new guielement('tts_text_row',
                '<tr class="IVROptionsDTMF"><td>'
                . ipbx_label(__('Text'), __('Text to be spoken when no recording is selected. The audio is generated by the system TTS engine.'))
                . '</td><td><textarea rows="5" cols="24" style="width:100%" name="tts_text" id="tts_text">'
                . htmlentities($tts_text)
                . '</textarea></td></tr>'));

        $tts_script = <<<'JS'
<tr><td colspan="2">
<script>
function ivrToggleTts() {
    var hasRecording = $('#announcement').val() !== '' && $('#announcement').val() !== null;
    $('#tts_lang').prop('disabled', hasRecording).trigger('chosen:updated');
    $('#tts_text').prop('disabled', hasRecording);
}
$(function () {
    $('#announcement').on('change', ivrToggleTts);
    ivrToggleTts();
});
</script>
</td></tr>
JS;
        $currentcomponent->addguielem($section, new guielement('tts_script', $tts_script));
    }



    //direct dial
    $currentcomponent->addoptlistitem('directdial', '', __('Disabled'));
    $currentcomponent->addoptlistitem('directdial', 'ext-local', __('Extensions'));

    $currentcomponent->addgeneralarrayitem('directdial_help', 'disabled', __('Completely disabled'));
    $currentcomponent->addgeneralarrayitem('directdial_help', 'local', __('Enabled for all extensions on a system'));

    $currentcomponent->addguielem($section,
        new gui_selectbox('directdial', $currentcomponent->getoptlist('directdial'),
        $ivr['directdial'], __('Direct Dial'), __('Provides options for callers to direct dial an extension. Direct dialing can be:')
        . ul($currentcomponent->getgeneralarray('directdial_help')), false));

    //add default to the recordings list. We dont want this for the general announcement, so we do it here
    $currentcomponent->addoptlistitem('recordings', 'default', __('Default'));
    //$currentcomponent->addguielem($section, new gui_textbox('timeout_time', stripslashes($ivr['timeout_time']), __('Timeout'), __('Amount of time to be concidered a timeout')));
    $currentcomponent->addguielem($section, new guielement('timeout_time',
        '<tr class="IVROptionsDTMF"><td>' . ipbx_label(__('Timeout'), __('Amount of time to be considered a timeout')).'</td><td><input type="number" name="timeout_time" value="'
                    . $ivr['timeout_time']
                    .'" required class="w100 input"></td></tr>'));
    //invalid
    $currentcomponent->addguielem($section,
        new gui_selectbox('invalid_loops', $currentcomponent->getoptlist('ivr_repeat_loops'),
        $ivr['invalid_loops'], __('Invalid Retries'), __('Number of times to retry when receiving an invalid/unmatched response from the caller'), false));
    $currentcomponent->addguielem($section,
        new gui_selectbox('invalid_retry_recording', $currentcomponent->getoptlist('recordings'),
        $ivr['invalid_retry_recording'], __('Invalid Retry Recording'), __('Prompt to be played when an invalid/unmatched response is received, before prompting the caller to try again'), false));

    if(!isset($ivr['invalid_append_announce'])) $ivr['invalid_append_announce']=0;

    $currentcomponent->addguielem($section,
        new gui_switch('invalid_append_announce', $ivr['invalid_append_announce'], __('Append Announcement on Invalid'), __('After playing the Invalid Retry Recording the system will replay the main IVR Announcement')));

    $currentcomponent->addguielem($section,
        new gui_switch('invalid_ivr_ret', $ivr['invalid_ivr_ret'], __('Return on Invalid'), __('Check this box to have this option return to a parent IVR if it was called '
            . 'from a parent IVR. If not, it will go to the chosen destination.<br><br>'
            . 'The return path will be to any IVR that was in the call path prior to this '
            . 'IVR which could lead to strange results if there was an IVR called in the '
            . 'call path but not immediately before this')));

    $currentcomponent->addguielem($section,
        new gui_selectbox('invalid_recording', $currentcomponent->getoptlist('recordings'),
        $ivr['invalid_recording'], __('Invalid Recording'), __('Prompt to be played before sending the caller to an alternate destination due to the caller pressing 0 or receiving the maximum amount of invalid/unmatched responses (as determined by Invalid Retries)'), false));
    $currentcomponent->addguielem($section,
        new gui_drawselects('invalid_destination', 'invalid', $ivr['invalid_destination'], __('Invalid Destination'),
         __('Destination to send the call to after Invalid Recording is played.'), true));

    //timeout
    $currentcomponent->addguielem($section,
        new gui_selectbox('timeout_loops', $currentcomponent->getoptlist('ivr_repeat_loops'),
        $ivr['timeout_loops'], __('Timeout Retries'), __('Number of times to retry when no DTMF is heard and the IVR choice times out.'), false));
    $currentcomponent->addguielem($section,
        new gui_selectbox('timeout_retry_recording', $currentcomponent->getoptlist('recordings'),
        $ivr['timeout_retry_recording'], __('Timeout Retry Recording'), __('Prompt to be played when a timeout occurs, before prompting the caller to try again'), false));

    if(!isset($ivr['timeout_append_announce'])) $ivr['timeout_append_announce']=0;
    $currentcomponent->addguielem($section,
        new gui_switch('timeout_append_announce', $ivr['timeout_append_announce'], __('Append Announcement on Timeout'), __('After playing the Timeout Retry Recording the system will replay the main IVR Announcement')));

    $currentcomponent->addguielem($section,
        new gui_switch('timeout_ivr_ret', $ivr['timeout_ivr_ret'], __('Return on Timeout'), __('Check this box to have this option return to a parent IVR if it was called '
            . 'from a parent IVR. If not, it will go to the chosen destination.<br><br>'
            . 'The return path will be to any IVR that was in the call path prior to this '
            . 'IVR which could lead to strange results if there was an IVR called in the '
            . 'call path but not immediately before this')));

    $currentcomponent->addguielem($section,
        new gui_selectbox('timeout_recording', $currentcomponent->getoptlist('recordings'),
        $ivr['timeout_recording'], __('Timeout Recording'), __('Prompt to be played before sending the caller to an alternate destination due to the caller pressing 0 or receiving the maximum amount of invalid/unmatched responses (as determined by Invalid Retries)'), false));
    $currentcomponent->addguielem($section,
        new gui_drawselects('timeout_destination', 'timeout',
        $ivr['timeout_destination'], __('Timeout Destination'), __('Destination to send the call to after Timeout Recording is played.'), true));

    //return to ivr
    $currentcomponent->addguielem($section,
        new gui_switch('retvm', $ivr['retvm'], __('Return to IVR after VM'), __('If checked, upon exiting voicemail a caller will be returned to this IVR if they got a users voicemail')));

    /*$currentcomponent->addguielem($section,
        new gui_switch('say_extension', $dir['say_extension'], __('Announce Extension'),
        __('When checked, the extension number being transferred to will be announced prior to the transfer'),true));*/
    $currentcomponent->addguielem($section, new gui_hidden('extdisplay', $ivr['id']));
    $currentcomponent->addguielem($section, new gui_hidden('action', 'save'));

    $section = __('IVR Entries');
    //draw the entries part of the table. A bit hacky perhaps, but hey - it works!
    $currentcomponent->addguielem($section, new guielement('rawhtml', ivr_draw_entries($ivr['id']), ''), 6);
}

function ivr_configpageinit($pagename) {
    global $currentcomponent;
    $action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';
    $extdisplay = isset($_REQUEST['extdisplay']) ? $_REQUEST['extdisplay'] : '';

    if($pagename == 'ivr'){
        $currentcomponent->addprocessfunc('ivr_configprocess');

        //dont show page if there is no action set
//        if ($action && $action != 'delete' || $extdisplay) {
            $currentcomponent->addguifunc('ivr_configpageload');
//        }
// nico
    return true;
    }
}

//prosses received arguments
function ivr_configprocess(){
    if (isset($_REQUEST['display']) && $_REQUEST['display'] == 'ivr'){
        global $db;
        //get variables

        $get_var = array('extdisplay', 'name', 'description', 'announcement',
                        'directdial', 'invalid_loops', 'invalid_retry_recording',
                        'invalid_destination', 'invalid_recording',
                        'retvm', 'timeout_time', 'timeout_recording',
                        'timeout_retry_recording', 'timeout_destination', 'timeout_loops',
                        'timeout_append_announce', 'invalid_append_announce',
                        'timeout_ivr_ret', 'invalid_ivr_ret');
        foreach($get_var as $var){
            $vars[$var] = isset($_REQUEST[$var])     ? $_REQUEST[$var]        : '';
        }
        $vars['timeout_append_announce'] = empty($vars['timeout_append_announce']) ? '0' : '1';
        $vars['invalid_append_announce'] = empty($vars['invalid_append_announce']) ? '0' : '1';
        $vars['timeout_ivr_ret'] = empty($vars['timeout_ivr_ret']) ? '0' : '1';
        $vars['invalid_ivr_ret'] = empty($vars['invalid_ivr_ret']) ? '0' : '1';
        $vars['announcement']=intval($vars['announcement']);

        $action        = isset($_REQUEST['action'])    ? $_REQUEST['action']    : '';
        $entries    = isset($_REQUEST['entries'])    ? $_REQUEST['entries']    : '';

        // The TTS fields are disabled when a recording is selected, so they are
        // not submitted. Preserve the stored values instead of clearing them.
        $vars['tts_text'] = (isset($_REQUEST['tts_text']) && is_string($_REQUEST['tts_text'])) ? $_REQUEST['tts_text'] : null;
        $vars['tts_lang'] = (isset($_REQUEST['tts_lang']) && is_string($_REQUEST['tts_lang'])) ? $_REQUEST['tts_lang'] : null;
        if ($vars['tts_text'] === null || $vars['tts_lang'] === null) {
            $existing = (!empty($vars['extdisplay']) && $action !== 'delete')
                ? ivr_get_details($vars['extdisplay']) : array();
            if ($vars['tts_text'] === null) {
                $vars['tts_text'] = isset($existing['tts_text']) ? $existing['tts_text'] : '';
            }
            if ($vars['tts_lang'] === null) {
                $vars['tts_lang'] = isset($existing['tts_lang']) ? $existing['tts_lang'] : '';
            }
        }
        $vars['tts_text'] = trim((string)$vars['tts_text']);
        if (function_exists('issabel_tts_normalize_lang')) {
            $default_lang = function_exists('issabel_tts_default_lang') ? issabel_tts_default_lang() : 'en-US';
            $vars['tts_lang'] = issabel_tts_normalize_lang((string)$vars['tts_lang'], $default_lang);
        } else {
            $vars['tts_lang'] = trim((string)$vars['tts_lang']);
        }

        switch ($action) {
            case 'save':
                //get real dest
                $vars['extdisplay'] = $vars['id'] = ivr_save_details($vars);
                ivr_save_entries($vars['extdisplay'], $entries);
                needreload();
                //$_REQUEST['action'] = 'edit';
                $this_dest = ivr_getdest($vars['extdisplay']);
                fwmsg::set_dest($this_dest[0]);
                $_SESSION['msg']=base64_encode(_dgettext('amp','Item has been saved'));
                $_SESSION['msgtype']='success';
                $_SESSION['msgtstamp']=time();
                if (function_exists('issabel_tts_enabled') && issabel_tts_enabled()
                    && empty($vars['announcement']) && trim((string)$vars['tts_text']) !== '') {
                    $tts_prompt = ivr_tts_prompt_filename($vars['tts_text'], $vars['tts_lang']);
                    if ($tts_prompt === '') {
                        $_SESSION['msg'] = base64_encode(__('The IVR was saved, but the TTS prompt could not be generated. Check that Piper and the selected voice are installed.'));
                        $_SESSION['msgtype'] = 'warning';
                        $_SESSION['msgtstamp'] = time();
                    }
                }
                redirect_standard_continue('extdisplay');
            break;
            case 'delete':
                ivr_delete($vars['extdisplay']);
                needreload();
                $_SESSION['msg']=base64_encode(_dgettext('amp','Item has been deleted'));
                $_SESSION['msgtype']='warning';
                $_SESSION['msgtstamp']=time();
                redirect_standard_continue();
            break;
        }
    }
}

//save ivr settings
function ivr_save_details($vals){
    global $db, $amp_conf;

    ivr_check_alter_table();

    foreach($vals as $key => $value) {
        $vals[$key] = $db->escapeSimple($value);
    }

    if ($vals['extdisplay']) {
        $sql = 'REPLACE INTO ivr_details (id, name, description, announcement,
                directdial, invalid_loops, invalid_retry_recording,
                invalid_destination, invalid_recording,
                retvm, timeout_time, timeout_recording,
                timeout_retry_recording, timeout_destination, timeout_loops,
                timeout_append_announce, invalid_append_announce, timeout_ivr_ret, invalid_ivr_ret,
                tts_text, tts_lang)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $foo = $db->query($sql, $vals);
        if($db->IsError($foo)) {
            die_issabelpbx(print_r($vals,true).' '.$foo->getDebugInfo());
        }
    } else {
        unset($vals['extdisplay']);
        $sql = 'INSERT INTO ivr_details (name, description, announcement,
                directdial, invalid_loops, invalid_retry_recording,
                invalid_destination,  invalid_recording,
                retvm, timeout_time, timeout_recording,
                timeout_retry_recording, timeout_destination, timeout_loops,
                timeout_append_announce, invalid_append_announce, timeout_ivr_ret, invalid_ivr_ret,
                tts_text, tts_lang)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';

        $foo = $db->query($sql, $vals);
        if($db->IsError($foo)) {
            die_issabelpbx(print_r($vals,true).' '.$foo->getDebugInfo());
        }
        $sql = ( (preg_match("/qlite/",$amp_conf["AMPDBENGINE"])) ? 'SELECT last_insert_rowid()' : 'SELECT LAST_INSERT_ID()');
        $vals['extdisplay'] = $db->getOne($sql);
        if ($db->IsError($foo)){
            die_issabelpbx($foo->getDebugInfo());
        }
    }

    return $vals['extdisplay'];
}

//save ivr entires
function ivr_save_entries($id, $entries){
    global $db;
    $id = $db->escapeSimple($id);
    sql('DELETE FROM ivr_entries WHERE ivr_id = "' . $id . '"');

    if ($entries) {
        for ($i = 0; $i < count($entries['ext']); $i++) {
            //make sure there is an extension & goto set - otherwise SKIP IT
            if (trim($entries['ext'][$i]) != '' && $entries['goto'][$i]) {
                $d[] = array(
                            'ivr_id'    => $id,
                            'selection'     => $entries['ext'][$i],
                            'dest'        => $entries['goto'][$i],
                            'ivr_ret'    => (isset($entries['ivr_ret'][$i]) ? intval($entries['ivr_ret'][$i]) : ''),
                            'spoken'    => isset($entries['spoken'][$i])?$entries['spoken'][$i]:''
                        );
            }

        }
        $sql = $db->prepare('INSERT INTO ivr_entries VALUES (?, ?, ?, ?, ?)');
        $res = $db->executeMultiple($sql, $d);
        if ($db->IsError($res)){
            die_issabelpbx($res->getDebugInfo());
        }
    }

    return true;
}

//draw ivr entires table header
function ivr_draw_entries_table_header_ivr() {
    global $astman;
    $show_spoken=0;
    $engineinfo = engine_getinfo();
    $astver     = $engineinfo['version'];
    $ast_ge_1616 = version_compare($astver, '16.16', 'ge');
    if(file_exists("/etc/asterisk/res-speech-vosk.conf") && $ast_ge_1616) {
        $show_spoken=1;
    }
    $rt = $astman->send_request("Command", array("Command" => "aeap show clients"));
    if($rt['Response']=='Success') { $show_spoken=1; }

    $headers = array();
    $headers[] = ipbx_label(__('Ext'),__('Any digit selection will be saved in the IVR_DIGIT_PRESSED chanel variable'));
    $headers[] = __('Destination');
    $headers[] = ipbx_label(__('Return'), __('Return to IVR'));

    if($show_spoken==1) {
        $headers[] = __('Spoken');
    }
    $headers[] = __('Delete');

    return $headers;
}

//draw actualy entires
function ivr_draw_entries($id){
    $headers        = mod_func_iterator('draw_entries_table_header_ivr');
    $ivr_entries    = ivr_get_entries($id);

    if ($ivr_entries) {
        foreach ($ivr_entries as $k => $e) {
            $entries[$k]= $e;
            $array = array('id' => $id, 'ext' => $e['selection']);
            $entries[$k]['hooks'] = mod_func_iterator('draw_entries_ivr', $array);
        }
    }

    $entries['blank'] = array('selection' => '', 'dest' => '', 'ivr_ret' => '', 'spoken'=>'');
    //assign to a vatriable first so that it can be passed by reference
    $array = array('id' => '', 'ext' => '');
    $entries['blank']['hooks'] = mod_func_iterator('draw_entries_ivr', $array);

    return load_view(dirname(__FILE__) . '/views/entries.php',
                array(
                    'headers'    => $headers,
                    'entries'    =>  $entries
                )
            );

}

//delete an ivr + entires
function ivr_delete($id) {
    global $db;
    sql('DELETE FROM ivr_details WHERE id = "' . $db->escapeSimple($id) . '"');
    sql('DELETE FROM ivr_entries WHERE ivr_id = "' . $db->escapeSimple($id) . '"');
}
//----------------------------------------------------------------------------
// Dynamic Destination Registry and Recordings Registry Functions
function ivr_check_destinations($dest=true) {
    global $active_modules;

    $destlist = array();
    if (is_array($dest) && empty($dest)) {
        return $destlist;
    }
    $sql = "SELECT dest, name, selection, a.id id FROM ivr_details a INNER JOIN ivr_entries d ON a.id = d.ivr_id  ";
    if ($dest !== true) {
        $sql .= "WHERE dest in ('".implode("','",$dest)."')";
    }
    $sql .= "ORDER BY name";
    $results = sql($sql,"getAll",DB_FETCHMODE_ASSOC);

    foreach ($results as $result) {
        $thisdest = $result['dest'];
        $thisid   = $result['id'];
        $name = $result['name'] ? $result['name'] : 'IVR ' . $thisid;
        $destlist[] = array(
            'dest' => $thisdest,
            'description' => sprintf(__("IVR: %s / Option: %s"),$name,$result['selection']),
            'edit_url' => 'config.php?display=ivr&action=edit&extdisplay='.urlencode($thisid),
        );
    }
    return $destlist;
}



function ivr_change_destination($old_dest, $new_dest) {
    global $db;
     $sql = "UPDATE ivr_entires SET dest = '$new_dest' WHERE dest = '$old_dest'";
     $db->query($sql);

}


function ivr_getdest($exten) {
    return array('ivr-'.$exten.',s,1');
}

function ivr_getdestinfo($dest) {
    global $active_modules;

    if (substr(trim($dest),0,4) == 'ivr-') {
        $exten = explode(',',$dest);
        $exten = substr($exten[0],4);

        $thisexten = ivr_get_details($exten);
        if (empty($thisexten)) {
            return array();
        } else {
            //$type = isset($active_modules['ivr']['type'])?$active_modules['ivr']['type']:'setup';
            return array('description' => sprintf(__("IVR: %s"), ($thisexten['name'] ? $thisexten['name'] : $thisexten['id'])),
                         'edit_url' => 'config.php?display=ivr&action=edit&extdisplay='.urlencode($exten),
                                  );
        }
    } else {
        return false;
    }
}

function ivr_recordings_usage($recording_id) {
    global $active_modules;

    $sql = "SELECT `id`, `name` FROM `ivr_details` WHERE `announcement` = '$recording_id' OR `invalid_retry_recording` = '$recording_id' OR `invalid_recording` = '$recording_id' OR `timeout_recording` = '$recording_id' OR `timeout_retry_recording` = '$recording_id'";
    $results = sql($sql, "getAll",DB_FETCHMODE_ASSOC);
    if (empty($results)) {
        return array();
    } else {
        foreach ($results as $result) {
            $usage_arr[] = array(
                'url_query' => 'config.php?display=ivr&action=edit&extdisplay='.urlencode($result['id']),
                'description' => sprintf(__("IVR: %s"), ($result['name'] ? $result['name'] : $result['id'])),
            );
        }
        return $usage_arr;
    }
}

?>
