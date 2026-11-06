<?php
/* vim: set expandtab tabstop=4 softtabstop=4 shiftwidth=4:
   Codificación: UTF-8
   +----------------------------------------------------------------------+
   | Issabel version 6.0                                                  |
   | http://www.issabel.org                                               |
   +----------------------------------------------------------------------+
   | Copyright (c) 2026 Issabel Foundation                                |
   +----------------------------------------------------------------------+
   | The contents of this file are subject to the General Public License  |
   | (GPL) Version 2 (the "License"); you may not use this file except in |
   | compliance with the License. You may obtain a copy of the License at |
   | http://www.opensource.org/licenses/gpl-license.php                   |
   +----------------------------------------------------------------------+
*/

require_once dirname(__DIR__) . '/phpagi.php';

/**
 * Thin AGI layer on top of phpagi.
 *
 * It only exposes what the TTS engine needs from the channel: the native
 * audio format, the answer state, streaming with interrupt digits and the
 * SET EXTENSION/PRIORITY pair used by the announcement module to route the
 * caller after a key press.
 */
class issabelTtsAgi
{
    private $agi;

    public function __construct()
    {
        $this->agi = new AGI();
    }

    public function debug($message, $level = 3)
    {
        $this->agi->verbose($message, $level);
    }

    public function nativeFormat()
    {
        $reply = $this->agi->get_full_variable('${CHANNEL(audionativeformat)}');
        $format = isset($reply['data']) ? $reply['data'] : '';

        if (preg_match('/(silk|sln)12/', $format)) {
            return array('ext' => 'sln12', 'rate' => 12000);
        }
        if (preg_match('/(speex|slin|silk)16|g722|siren7/', $format)) {
            return array('ext' => 'sln16', 'rate' => 16000);
        }
        if (preg_match('/(speex|slin|celt)32|siren14/', $format)) {
            return array('ext' => 'sln32', 'rate' => 32000);
        }
        if (preg_match('/(celt|slin)44/', $format)) {
            return array('ext' => 'sln44', 'rate' => 44100);
        }
        if (preg_match('/(celt|slin)48/', $format)) {
            return array('ext' => 'sln48', 'rate' => 48000);
        }
        return array('ext' => 'sln', 'rate' => 8000);
    }

    public function answerIfNeeded()
    {
        $status = $this->agi->channel_status();
        if (isset($status['result']) && (int)$status['result'] === 4) {
            $this->agi->answer();
        }
    }

    /**
     * Stream a file and return the DTMF code the caller pressed, 0 if the
     * file finished and -1 on error.
     */
    public function stream($file, $keys)
    {
        // STREAM FILE takes a file name without extension; Asterisk appends the
        // best format for the channel. The cache stores raw files with the
        // format in the extension, so strip the last one here.
        $file = preg_replace('/\.[^.\/]+$/', '', $file);
        $reply = $this->agi->stream_file($file, $keys);
        return isset($reply['result']) ? (int)$reply['result'] : -1;
    }

    public function routeDigit($code)
    {
        $this->agi->set_extension(chr($code));
        $this->agi->set_priority(1);
    }
}
