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

/**
 * Helpers shared by the modules that write a TTS call into the dialplan:
 * announcement, tts and (through the call file) the calendar.
 *
 * Keeping the argument layout in one place avoids the modules drifting
 * apart, which is how announcement and tts ended up with two different
 * AGI contracts in the first place.
 */

if (!function_exists('issabel_tts_agi_path')) {

    function issabel_tts_agi_path()
    {
        global $amp_conf;
        $dir = !empty($amp_conf['ASTAGIDIR']) ? $amp_conf['ASTAGIDIR'] : '/var/lib/asterisk/agi-bin';
        return rtrim($dir, '/') . '/issabel-tts.agi';
    }

    function issabel_tts_enabled()
    {
        return is_file(issabel_tts_agi_path());
    }

    /**
     * Sanitize a text for a quoted AGI argument inside extensions_additional.conf.
     *
     * Double quotes and backslashes would break the dialplan quoting and a
     * semicolon could start a comment, so they are replaced. Control
     * characters are collapsed to a single space.
     */
    function issabel_tts_normalize_text($text)
    {
        $text = (string)$text;
        if (function_exists('mb_check_encoding') && !mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }
        $text = str_replace(array("\r\n", "\r", "\n", "\t"), ' ', $text);
        $text = str_replace(array('"', '\\'), ' ', $text);
        $text = str_replace(';', ',', $text);
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }

    function issabel_tts_clean_keys($keys)
    {
        return preg_replace('/[^0-9*#]/', '', (string)$keys);
    }

    /**
     * Build the AGI() argument string for issabel-tts.agi.
     *
     * @param string $text Text to synthesize.
     * @param array  $opts engine, cmd, params, gui, keys, route, speed.
     * @return string Empty string when there is nothing to say.
     */
    function issabel_tts_agi_args($text, $opts = array())
    {
        $engine = isset($opts['engine']) && $opts['engine'] !== '' ? $opts['engine'] : 'piper';
        $cmd    = isset($opts['cmd']) ? (string)$opts['cmd'] : '';
        if ($cmd === '' && $engine === 'piper') {
            $cmd = '/usr/bin/piper';
        }

        $params = isset($opts['params']) ? $opts['params'] : array();
        if (is_string($params)) {
            $json = $params;
        } else {
            $json = json_encode($params);
            if ($json === false) {
                $json = '{}';
            }
        }

        $gui   = !empty($opts['gui']) ? 1 : 0;
        $keys  = isset($opts['keys']) ? issabel_tts_clean_keys($opts['keys']) : '';
        $route = !empty($opts['route']) ? 1 : 0;
        $speed = isset($opts['speed']) && is_numeric($opts['speed']) ? (float)$opts['speed'] : 1.0;
        if ($speed < 0.5) {
            $speed = 0.5;
        } elseif ($speed > 2.0) {
            $speed = 2.0;
        }

        $text = issabel_tts_normalize_text($text);
        if ($text === '') {
            return '';
        }

        return 'issabel-tts.agi,"' . $text . '",' . $engine . ','
            . base64_encode($cmd) . ',' . base64_encode($json) . ','
            . $gui . ',"' . $keys . '",' . $route . ',' . sprintf('%.2f', $speed);
    }
}
