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

// Pico TTS is no longer shipped in Issabel 6. Point existing engines and TTS
// entries at Piper so upgrades keep working without the administrator having
// to recreate them. The update is idempotent.
$db->query("UPDATE tts_engines SET ttsengine_engine = 'piper', ttsengine_cmd = '/usr/bin/piper', ttsengine_template = '{\"voice\": \"es_MX-claude-high.onnx\"}' WHERE ttsengine_engine = 'pico'");
$db->query("UPDATE tts SET tts_engine = 'piper' WHERE tts_engine = 'pico'");

// Fresh installs have no engine rows at all, so seed Piper. Without this the
// Text to Speech page has nothing to select and app-tts gets an empty command.
$check = $db->getRow("SELECT ttsengine_id FROM tts_engines WHERE ttsengine_engine = 'piper'");
if (empty($check)) {
    $db->query("INSERT INTO tts_engines (ttsengine_description, ttsengine_engine, ttsengine_cmd, ttsengine_template) VALUES ('Piper', 'piper', '/usr/bin/piper', '{\"voice\": \"es_MX-claude-high.onnx\"}')");
}

