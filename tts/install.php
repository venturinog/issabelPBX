<?php
if (!defined('ISSABELPBX_IS_AUTH')) { die('No direct script access allowed'); }

global $db;

$autoincrement=(preg_match("/qlite/",$amp_conf["AMPDBENGINE"])) ? "AUTOINCREMENT":"AUTO_INCREMENT";

$sql['tts'] = "CREATE TABLE IF NOT EXISTS tts (
    tts_id INTEGER NOT NULL PRIMARY KEY $autoincrement,
    tts_description VARCHAR(250),
    tts_engine VARCHAR(200),
    tts_parameters text,
    tts_text text,
    dest VARCHAR(250)
)";

$sql['tts_engines'] = "CREATE TABLE IF NOT EXISTS tts_engines (
    ttsengine_id INTEGER NOT NULL PRIMARY KEY $autoincrement,
    ttsengine_description VARCHAR(250) ,
    ttsengine_engine VARCHAR(100),
    ttsengine_cmd text,
    ttsengine_template text
)";

foreach ($sql as $tablename=>$q) {
    $check = $db->query($q);
    if($db->IsError($check)) {
        die_issabelpbx("Can not create $tablename table\n");
    }
}

// Very old installations may predate the per-entry parameter column. The
// SELECT is portable between MariaDB and SQLite.
$check = $db->query("SELECT tts_parameters FROM tts LIMIT 0");
if ($db->IsError($check)) {
    $db->query("ALTER TABLE tts ADD tts_parameters text");
}

$default_lang = function_exists('issabel_tts_default_lang') ? issabel_tts_default_lang() : 'en-US';
$default_template = json_encode(array('lang' => $default_lang));
if ($default_template === false) {
    $default_template = '{"lang":"en-US"}';
}

// Pico TTS is no longer shipped in Issabel 6. Point existing engines and TTS
// entries at Piper so upgrades keep working without the administrator having
// to recreate them. The update is idempotent.
$db->query("UPDATE tts_engines SET ttsengine_engine = 'piper', ttsengine_cmd = '/usr/bin/piper', ttsengine_template = '".$db->escapeSimple($default_template)."' WHERE ttsengine_engine = 'pico'");
$db->query("UPDATE tts SET tts_engine = 'piper' WHERE tts_engine = 'pico'");

// Normalize the six known Piper voice files to {"lang":"..."}. Keep custom
// voices, malformed JSON and extra keys untouched. This is idempotent and only
// touches rows that still use the old file-name form.
$voice_to_lang = array(
    'es_MX-claude-high.onnx'      => 'es-MX',
    'en_US-ljspeech-medium.onnx'  => 'en-US',
    'pt_BR-faber-medium.onnx'     => 'pt-BR',
    'fr_FR-siwis-medium.onnx'     => 'fr-FR',
    'it_IT-paola-medium.onnx'     => 'it-IT',
    'de_DE-thorsten-medium.onnx'  => 'de-DE',
);
$rows = $db->getAll("SELECT ttsengine_id, ttsengine_template FROM tts_engines WHERE ttsengine_engine = 'piper'", DB_FETCHMODE_ASSOC);
if (is_array($rows)) {
    foreach ($rows as $row) {
        $tpl = json_decode((string)$row['ttsengine_template'], true);
        if (!is_array($tpl) || !empty($tpl['lang']) || empty($tpl['voice']) || !is_string($tpl['voice'])) {
            continue;
        }
        $voice = basename($tpl['voice']);
        if (!isset($voice_to_lang[$voice])) {
            continue;
        }
        unset($tpl['voice']);
        $tpl['lang'] = $voice_to_lang[$voice];
        $encoded = json_encode($tpl);
        if ($encoded === false) {
            continue;
        }
        $db->query("UPDATE tts_engines SET ttsengine_template = '".$db->escapeSimple($encoded)."' WHERE ttsengine_id = ".$db->escapeSimple($row['ttsengine_id']));
    }
}

// Fresh installs have no engine rows at all, so seed Piper. Without this the
// Text to Speech page has nothing to select and app-tts gets an empty command.
$check = $db->getRow("SELECT ttsengine_id FROM tts_engines WHERE ttsengine_engine = 'piper'");
if (empty($check)) {
    $db->query("INSERT INTO tts_engines (ttsengine_description, ttsengine_engine, ttsengine_cmd, ttsengine_template) VALUES ('Piper', 'piper', '/usr/bin/piper', '".$db->escapeSimple($default_template)."')");
}

