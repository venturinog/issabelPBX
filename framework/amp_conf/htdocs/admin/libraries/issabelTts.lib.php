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

    /**
     * Read /etc/issabel/piper.conf once. The web pages only need a few keys,
     * but keeping the parser here avoids a second INI implementation.
     */
    function issabel_tts_config()
    {
        static $cfg = null;
        if ($cfg !== null) {
            return $cfg;
        }
        $cfg = array();
        $file = '/etc/issabel/piper.conf';
        if (is_readable($file)) {
            $ini = @parse_ini_file($file, true, INI_SCANNER_RAW);
            if (is_array($ini)) {
                $cfg = $ini;
            }
        }
        return $cfg;
    }

    function issabel_tts_catalog_path()
    {
        $cfg = issabel_tts_config();
        if (isset($cfg['paths']['catalog']) && $cfg['paths']['catalog'] !== '') {
            return $cfg['paths']['catalog'];
        }
        return '/usr/share/issabel-piper/voices.json';
    }

    function issabel_tts_voice_dir()
    {
        $cfg = issabel_tts_config();
        if (isset($cfg['paths']['voice_dir']) && $cfg['paths']['voice_dir'] !== '') {
            return rtrim($cfg['paths']['voice_dir'], '/');
        }
        return '/usr/share/issabel-piper/voices';
    }

    /**
     * Catalog of supported Piper voices, shared with the AGI. The fallback is
     * deliberately small: it keeps the GUI usable if the piper RPM has not
     * been upgraded yet.
     */
    function issabel_tts_catalog()
    {
        static $catalog = null;
        if ($catalog !== null) {
            return $catalog;
        }

        $fallback = array(
            'aliases' => array(
                'en' => 'en-US', 'en-GB' => 'en-US',
                'es' => 'es-MX', 'es-ES' => 'es-MX',
                'pt' => 'pt-BR', 'pt-PT' => 'pt-BR', 'br' => 'pt-BR',
                'fr' => 'fr-FR', 'it' => 'it-IT', 'de' => 'de-DE',
            ),
            'voices' => array(
                'es-MX' => array('file' => 'es_MX-claude-high.onnx', 'label' => 'Spanish (Mexico)', 'native' => 'Español (México)'),
                'en-US' => array('file' => 'en_US-ljspeech-medium.onnx', 'label' => 'English (United States)', 'native' => 'English (United States)'),
                'pt-BR' => array('file' => 'pt_BR-faber-medium.onnx', 'label' => 'Portuguese (Brazil)', 'native' => 'Português (Brasil)'),
                'fr-FR' => array('file' => 'fr_FR-siwis-medium.onnx', 'label' => 'French (France)', 'native' => 'Français (France)'),
                'it-IT' => array('file' => 'it_IT-paola-medium.onnx', 'label' => 'Italian (Italy)', 'native' => 'Italiano (Italia)'),
                'de-DE' => array('file' => 'de_DE-thorsten-medium.onnx', 'label' => 'German (Germany)', 'native' => 'Deutsch (Deutschland)'),
            ),
        );

        $path = issabel_tts_catalog_path();
        if (is_readable($path)) {
            $json = @file_get_contents($path);
            if ($json !== false) {
                $data = json_decode($json, true);
                if (is_array($data) && !empty($data['voices']) && is_array($data['voices'])) {
                    if (!isset($data['aliases']) || !is_array($data['aliases'])) {
                        $data['aliases'] = $fallback['aliases'];
                    }
                    $catalog = $data;
                    return $catalog;
                }
            }
        }

        $catalog = $fallback;
        return $catalog;
    }

    /**
     * Language currently selected for the GUI. In the legacy framework this is
     * get_language(); in the IssabelPBX admin it is the LANGUAGE setting or the
     * administrator cookie. TTS is PBX-wide, so never use a per-request value
     * at AGI runtime: this helper is only used by the GUI and the CLI.
     */
    function issabel_tts_gui_lang()
    {
        global $issabelpbx_conf, $amp_conf;

        if (function_exists('get_language')) {
            $lang = @get_language();
            if (!empty($lang)) {
                return $lang;
            }
        }
        // The legacy framework stores the system language in SQLite. Reading
        // it here keeps the module installer and the CLI independent from the
        // framework bootstrap.
        $settings_db = '/var/www/db/settings.db';
        if (is_file($settings_db) && class_exists('PDO')) {
            try {
                $pdo = new PDO('sqlite:' . $settings_db);
                $stmt = $pdo->query("SELECT value FROM settings WHERE key = 'language' LIMIT 1");
                $lang = $stmt ? $stmt->fetchColumn() : '';
                if (!empty($lang)) {
                    return $lang;
                }
            } catch (Exception $e) {
                // Fall through to the other sources.
            }
        }
        if (is_object($issabelpbx_conf) && method_exists($issabelpbx_conf, 'get_conf_setting')) {
            $lang = $issabelpbx_conf->get_conf_setting('LANGUAGE');
            if (!empty($lang)) {
                return $lang;
            }
        }
        if (!empty($_COOKIE['lang']) && is_string($_COOKIE['lang'])) {
            return $_COOKIE['lang'];
        }
        if (!empty($amp_conf['LANGUAGE'])) {
            return $amp_conf['LANGUAGE'];
        }
        return 'en';
    }

    function issabel_tts_default_lang()
    {
        $cfg = issabel_tts_config();
        $raw = (isset($cfg['voice']['default_lang']) && is_string($cfg['voice']['default_lang']))
            ? trim($cfg['voice']['default_lang']) : '';
        if ($raw !== '' && strtolower($raw) !== 'auto') {
            $lang = issabel_tts_normalize_lang($raw, '');
            if ($lang !== '') {
                return $lang;
            }
        }

        $lang = issabel_tts_normalize_lang(issabel_tts_gui_lang(), '');
        return $lang !== '' ? $lang : 'en-US';
    }

    /**
     * Normalize a language code (es, es_ES, es-ES, br, ...) to a catalog key.
     * Return an empty string when the code is not supported and no fallback
     * was supplied.
     */
    function issabel_tts_normalize_lang($lang, $fallback = '')
    {
        if (!is_string($lang) && !is_numeric($lang)) {
            return $fallback;
        }
        $lang = strtolower(trim((string)$lang));
        $lang = str_replace('_', '-', $lang);
        if ($lang === '') {
            return $fallback;
        }

        $catalog = issabel_tts_catalog();
        $voices = isset($catalog['voices']) && is_array($catalog['voices']) ? $catalog['voices'] : array();
        $aliases = isset($catalog['aliases']) && is_array($catalog['aliases']) ? $catalog['aliases'] : array();

        foreach ($voices as $code => $voice) {
            if (strtolower($code) === $lang) {
                return $code;
            }
        }
        foreach ($aliases as $alias => $code) {
            if (strtolower($alias) === $lang && isset($voices[$code])) {
                return $code;
            }
        }

        $prefix = substr($lang, 0, 2);
        if ($prefix !== '') {
            foreach ($aliases as $alias => $code) {
                if (strtolower($alias) === $prefix && isset($voices[$code])) {
                    return $code;
                }
            }
            foreach ($voices as $code => $voice) {
                if (strtolower(substr($code, 0, 2)) === $prefix) {
                    return $code;
                }
            }
        }

        return $fallback;
    }

    function issabel_tts_lang_exists($lang)
    {
        return issabel_tts_normalize_lang($lang, '') !== '';
    }

    function issabel_tts_voice_file($lang)
    {
        $lang = issabel_tts_normalize_lang($lang, '');
        if ($lang === '') {
            return '';
        }
        $catalog = issabel_tts_catalog();
        return isset($catalog['voices'][$lang]['file']) ? $catalog['voices'][$lang]['file'] : '';
    }

    function issabel_tts_lang_for_voice($file)
    {
        $file = basename((string)$file);
        if ($file === '') {
            return '';
        }
        $catalog = issabel_tts_catalog();
        $voices = isset($catalog['voices']) && is_array($catalog['voices']) ? $catalog['voices'] : array();
        foreach ($voices as $code => $voice) {
            if (isset($voice['file']) && basename($voice['file']) === $file) {
                return $code;
            }
        }
        return '';
    }

    /**
     * List supported languages. If $only_installed is true, hidden voices are
     * filtered out; when none are installed we still return the catalog so the
     * page can render a useful message/options.
     */
    function issabel_tts_languages($only_installed = true)
    {
        $catalog = issabel_tts_catalog();
        $voices = isset($catalog['voices']) && is_array($catalog['voices']) ? $catalog['voices'] : array();
        if (!$only_installed) {
            return $voices;
        }

        $dir = issabel_tts_voice_dir();
        $installed = array();
        foreach ($voices as $code => $voice) {
            if (isset($voice['file']) && is_readable($dir . '/' . $voice['file'])) {
                $installed[$code] = $voice;
            }
        }
        return empty($installed) ? $voices : $installed;
    }

    function issabel_tts_language_options($only_installed = true)
    {
        $out = array();
        foreach (issabel_tts_languages($only_installed) as $code => $voice) {
            $label = isset($voice['label']) ? $voice['label'] : $code;
            if (function_exists('__')) {
                $label = __($label);
            }
            $native = isset($voice['native']) ? $voice['native'] : '';
            $out[$code] = ($native !== '' && $native !== $label) ? $label . ' — ' . $native : $label;
        }
        return $out;
    }

    /**
     * Extract the canonical language from a tts_engines template. Supports the
     * old {"voice":"file.onnx"} format and the new {"lang":"es-MX"} format.
     */
    function issabel_tts_lang_from_params($params)
    {
        if (is_string($params)) {
            $params = json_decode($params, true);
        }
        if (!is_array($params)) {
            return '';
        }
        // The engine gives "voice" priority over "lang"; keep the GUI in sync
        // with that order so a row with both fields is shown truthfully.
        if (!empty($params['voice']) && is_string($params['voice'])) {
            $lang = issabel_tts_lang_for_voice($params['voice']);
            if ($lang !== '') {
                return $lang;
            }
        }
        if (!empty($params['lang']) && is_string($params['lang'])) {
            if (strtolower($params['lang']) === 'auto') {
                return issabel_tts_default_lang();
            }
            $lang = issabel_tts_normalize_lang($params['lang'], '');
            if ($lang !== '') {
                return $lang;
            }
        }
        return '';
    }

    /**
     * Set or remove the language key in a template/parameters JSON string while
     * preserving any other keys.
     */
    function issabel_tts_merge_params($params, $lang)
    {
        if (is_string($params)) {
            $data = json_decode($params, true);
        } elseif (is_array($params)) {
            $data = $params;
        } else {
            $data = array();
        }
        if (!is_array($data)) {
            $data = array();
        }

        if ($lang === null || $lang === '') {
            unset($data['lang']);
        } else {
            $lang = issabel_tts_normalize_lang($lang, '');
            if ($lang !== '') {
                $data['lang'] = $lang;
            }
        }

        if (empty($data)) {
            return '';
        }
        $json = json_encode($data);
        return $json === false ? '' : $json;
    }
}
