<?php
/* vim: set expandtab tabstop=4 softtabstop=4 shiftwidth=4:
  Codificación: UTF-8
  +----------------------------------------------------------------------+
  | Issabel version 4.0                                                  |
  | http://www.issabel.org                                               |
  +----------------------------------------------------------------------+
  | Copyright (c) 2021 Issabel Foundation                                |
  +----------------------------------------------------------------------+
  | The contents of this file are subject to the General Public License  |
  | (GPL) Version 2 (the "License"); you may not use this file except in |
  | compliance with the License. You may obtain a copy of the License at |
  | http://www.opensource.org/licenses/gpl-license.php                   |
  |                                                                      |
  | Software distributed under the License is distributed on an "AS IS"  |
  | basis, WITHOUT WARRANTY OF ANY KIND, either express or implied. See  |
  | the License for the specific language governing rights and           |
  | limitations under the License.                                       |
  +----------------------------------------------------------------------+
*/
if (!defined('ISSABELPBX_IS_AUTH')) { die('No direct script access allowed'); }

$type    = isset($_REQUEST['type']) ? $_REQUEST['type'] : 'setup';
$action  = isset($_REQUEST['action']) ? $_REQUEST['action'] :  '';

if (isset($_REQUEST['delete'])) $action = 'delete';

$ttsengine_id          = isset($_REQUEST['ttsengine_id']) ? $_REQUEST['ttsengine_id'] :  false;
$description           = isset($_REQUEST['description']) ? $_REQUEST['description'] :  '';
$ttsengine_engine      = isset($_REQUEST['ttsengine_engine']) ? $_REQUEST['ttsengine_engine'] :  '';
$ttsengine_cmd         = isset($_REQUEST['ttsengine_cmd']) ? $_REQUEST['ttsengine_cmd'] :  '';
$ttsengine_template    = isset($_REQUEST['ttsengine_template']) ? $_REQUEST['ttsengine_template'] :  '';
$piper_lang            = (isset($_REQUEST['piper_lang']) && is_string($_REQUEST['piper_lang'])) ? $_REQUEST['piper_lang'] :  '';
$piper_default_lang    = function_exists('issabel_tts_default_lang') ? issabel_tts_default_lang() : 'en-US';

if ($ttsengine_engine === 'piper' && $piper_lang !== '' && $piper_lang !== 'custom') {
    $decoded = json_decode((string)$ttsengine_template, true);
    if (!is_array($decoded)) {
        $decoded = array();
    }
    unset($decoded['voice']);
    $ttsengine_template = issabel_tts_merge_params($decoded, $piper_lang);
} elseif ($ttsengine_engine === 'piper' && $piper_lang === 'custom') {
    $decoded = json_decode((string)$ttsengine_template, true);
    if (!is_array($decoded)) {
        $ttsengine_template = issabel_tts_merge_params('', $piper_default_lang);
        $piper_lang = $piper_default_lang;
    }
}

$templates = array(
'polly'=>'{
    "accessKeyId" :"",
    "secretAccessKey": "",
    "region": "us-east-1",
    "Engine": "neural|standard",
    "VoiceId": ""
}',
'piper'=>json_encode(array('lang' => $piper_default_lang)),
'azure'=>'{
    "gender":"Female",
    "lang":"es-MX",
    "voice":"es-MX-DaliaNeural",
    "azurekey":"",
    "region":"eastus",
}',
'elevenlabs'=>'{
    "voice":"",
    "elevenlabskey":"",
}',
'google'=>'{
    "languageCode":"es-US",
    "name":"es-US-Wavenet-A",
    "ssmlGender":"FEMALE",
    "credentials":"/path/to/google/credentials.json"
}',
'flite'=>'',
'custom'=>''
);

$commands = array(
'polly'=>'/usr/bin/node /opt/aws-nodejs/polly.js',
'piper'=>'/usr/bin/piper',
'flite'=>'/usr/bin/flite',
'azure'=>$amp_conf['ASTDATADIR'].'/agi-bin/azuretts.php',
'elevenlabs'=>$amp_conf['ASTDATADIR'].'/agi-bin/elevenlabstts.php',
'google'=>$amp_conf['ASTDATADIR'].'/agi-bin/googlewave.php',
'custom'=>$amp_conf['ASTDATADIR'].'/agi-bin/googletts.pl "{TEXT}" en 1.2 {OUTPUTFILE}'
);

echo "<script>";
echo "var ttstemplate = {};\n";
foreach($templates as $kname=>$tval) {
echo "ttstemplate['$kname']='".base64_encode($tval)."';\n";
}
echo "var ttscommand = {};\n";
foreach($commands as $kname=>$tval) {
echo "ttscommand['$kname']='".base64_encode($tval)."';\n";
}

echo "</script>";

switch ($action) {
    case 'add':
        ttsengine_add($description, $ttsengine_engine, $ttsengine_cmd, $ttsengine_template);
        needreload();
        $_SESSION['msg']=base64_encode(_dgettext('amp','Item has been added'));
        $_SESSION['msgtype']='success';
        $_SESSION['msgtstamp']=time();
        redirect_standard();
    break;
    case 'edit':
        ttsengine_edit($ttsengine_id, $description, $ttsengine_engine, $ttsengine_cmd, $ttsengine_template);
        needreload();
        $_SESSION['msg']=base64_encode(_dgettext('amp','Item has been saved'));
        $_SESSION['msgtype']='success';
        $_SESSION['msgtstamp']=time();
        redirect_standard('extdisplay');
    break;
    case 'delete':
        ttsengine_delete($ttsengine_id);
        needreload();
        $_SESSION['msg']=base64_encode(_dgettext('amp','Item has been deleted'));
        $_SESSION['msgtype']='warning';
        $_SESSION['msgtstamp']=time();
        redirect_standard();
    break;
}

$rnaventries = array();
$engines     = ttsengine_list();
foreach($engines as $row) {
    $rnaventries[] = array($row['ttsengine_id'],$row['ttsengine_description'],'','');
}
drawListMenu($rnaventries, $type, $display, $extdisplay);
?>
<div class='content'>
<?php
if ($extdisplay) {
    // load
    $row = ttsengine_get($extdisplay);
    $description = $row['ttsengine_description'];
    $ttsengine_engine     = htmlspecialchars($row['ttsengine_engine']);
    $ttsengine_template   = htmlspecialchars($row['ttsengine_template']);
    $ttsengine_cmd        = htmlspecialchars($row['ttsengine_cmd']);
    if ($ttsengine_engine === 'piper') {
        $rawText = trim((string)$row['ttsengine_template']);
        $raw = json_decode($rawText, true);
        $custom_voice = false;
        if (is_array($raw) && !empty($raw['voice']) && is_string($raw['voice'])) {
            $voice_file = basename($raw['voice']);
            if (issabel_tts_lang_for_voice($voice_file) === '') {
                $custom_voice = is_readable(issabel_tts_voice_dir() . '/' . $voice_file);
            }
        }
        if ($custom_voice) {
            $piper_lang = 'custom';
        } else {
            $piper_lang = issabel_tts_lang_from_params($rawText);
            if ($piper_lang === '') {
                $piper_lang = ($rawText !== '' && $rawText !== '{}' && $rawText !== '[]')
                    ? 'custom' : $piper_default_lang;
            }
        }
    }
} else {
    if ($piper_lang === '') {
        $piper_lang = $piper_default_lang;
    }
}

$helptext = __("The Text To Speech Engine module allows you to add different engines to convert text to speech");
$help = '<div class="infohelp">?<span style="display:none;">'.$helptext.'</span></div>';
echo "<div class='is-flex'><h2>";
echo ($extdisplay ? __("Edit Text to Speech Engine")." $description" : __("Add Text to Speech Engine"));
echo "</h2>$help</div>";

?>

<form id="mainform" name="editTexttospeechadmin" method="post" onsubmit="return checkTexttospeechadmin(this);">
    <input type="hidden" name="extdisplay" value="<?php echo $extdisplay; ?>">
    <input type="hidden" name="ttsengine_id" value="<?php echo $extdisplay; ?>">
    <input type="hidden" name="action" value="<?php echo ($extdisplay ? 'edit' : 'add'); ?>">
    <table class='table is-fullwidth is-narrow is-borderless'>
    <tr><td colspan="2"><h5><?php echo _dgettext('amp','General Settings');?></h5></td></tr>
    <tr>
        <td><a href="#" class="info"><?php echo __("Description")?><span><?php echo __("The descriptive name of this text to speech engine. For example \"new name here\"");?></span></a></td>
        <td><input class="input w100" type="text" name="description" value="<?php  echo $description; ?>" tabindex="<?php echo ++$tabindex;?>"></td>
    </tr>
    <tr>
        <td><a href="#" class="info"><?php echo __("Engine")?><span><?php echo __("The TTS engine to use for the text to speech entry");?></span></a></td>
        <td>
            <select name="ttsengine_engine" id="ttsengine_engine" tabindex="<?php echo ++$tabindex;?>" class='componentSelect'>
            <?php
                $engines = array('piper'=>'Piper TTS','polly'=>'Amazon Polly','azure'=>'Microsoft Azure TTS','flite'=>'FLite','google'=>'Google Cloud TTS', 'elevenlabs'=>'Eleven Labs', 'custom'=>'Custom');
                foreach ($engines as $key=>$name) {
                    echo '<option value="'.$key.'"'.($key == $ttsengine_engine ? ' SELECTED' : '').'>'.$name."</option>\n";
                }
            ?>
            </select>

        </td>
    </tr>
    <tr id="piperlangrow">
        <td><a href="#" class="info"><?php echo __("Language / Voice")?><span><?php echo __("The Piper voice used by this engine. The model file is selected automatically; you do not need to enter a file name.");?></span></a></td>
        <td>
            <select name="piper_lang" id="piper_lang" tabindex="<?php echo ++$tabindex;?>" class='componentSelect'>
                <?php
                foreach (issabel_tts_language_options() as $code => $label) {
                    echo '<option value="'.htmlspecialchars($code, ENT_QUOTES, 'UTF-8').'"'.($code == $piper_lang ? ' SELECTED' : '').'>'.htmlspecialchars($label, ENT_QUOTES, 'UTF-8')."</option>\n";
                }
                ?>
                <option value="custom"<?php echo ($piper_lang === 'custom' ? ' SELECTED' : ''); ?>><?php echo __("Custom / advanced JSON"); ?></option>
            </select>
        </td>
    </tr>
    <tr id='templatecell'>
        <td colspan=2><a href="#" class="info"><?php echo __("Template")?><span><?php echo __("Set of key=value pairs needed for the TTS engine. Usually API credentials, voice name, etc.");?></span></a></td>
   </tr>
   <tr id="templaterow">
        <td colspan=2><textarea id="ttstext" class='textarea' style='width:100px; height:10rem;' name="ttsengine_template" tabindex="<?php echo ++$tabindex;?>"><?php echo $ttsengine_template; ?></textarea></td>
    </tr>
    <tr>
        <td colspan=2><a href="#" class="info"><?php echo __("Command")?><span><?php echo __("The actual command line to run the engine.<br/><br/>There are two variables you must use within the command if you select a <strong>custom</strong> engine: <dl><dt>{TEXT}</dt><dd>The actual text to convert to speech</dd><dt>{OUTPUTFILE}</dt><dd>The file name where the sound file will be saved.</dd></dl>");?></span></a></td>
    </tr>
    <tr>
        <td colspan=2><textarea class='textarea' id='ttsenginecmd'  name="ttsengine_cmd" tabindex="<?php echo ++$tabindex;?>"><?php echo $ttsengine_cmd; ?></textarea></td>
    </tr>
</table>
</form>

<script>
                
function togglePiperOptions() {
  var isPiper = $('#ttsengine_engine').val() === 'piper';
  var isCustom = $('#piper_lang').val() === 'custom';
  $('#piperlangrow').toggle(isPiper);
  $('#templatecell, #templaterow').toggle(!isPiper || isCustom);
}

$(function () {

  if (!$('[name=description]').attr("value")) {
      $('[name=ttsengine_engine]').attr({value: "piper"});
  }
  $('#ttsengine_engine, #piper_lang').on('change', togglePiperOptions);
  togglePiperOptions();
  //$('#container').width($('#container').width() - $('.rnav').width() - 20).css('margin-left','-10px');

  
});

function checkTexttospeechadmin(theForm) {
    var msgInvalidDescription = "<?php echo __('Invalid description specified'); ?>";

    // set up the Destination stuff
    //setDestinations(theForm, '_post_dest');

    // form validation
    defaultEmptyOK = false;
    if (isEmpty(theForm.description.value))
        return warnInvalid(theForm.description, msgInvalidDescription);

    //if (!validateDestinations(theForm, 1, true))
    //    return false;

    $.LoadingOverlay('show');
    return true;
}
<?php echo js_display_confirmation_toasts(); ?>
</script>
</div> <!-- end div content, be sure to include script tags before -->
<?php echo form_action_bar($extdisplay); ?>
