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
   |                                                                      |
   | Software distributed under the License is distributed on an "AS IS"  |
   | basis, WITHOUT WARRANTY OF ANY KIND, either express or implied. See  |
   | the License for the specific language governing rights and           |
   | limitations under the License.                                       |
   +----------------------------------------------------------------------+
*/

/**
 * Text to speech engine used by issabel-tts.agi.
 *
 * The engine turns a text string into one or more audio files in the native
 * Asterisk format (sln, sln12, sln16, ...). It is independent from the AGI
 * protocol so that the recordings module can use it from the command line
 * (gui mode) and the dialplan can stream the result.
 *
 * Design notes:
 *  - Piper is the only engine that consumes a full voice model per process
 *    (~180 MiB), so it is the only one protected by the slot pool.
 *  - Every engine uses a per-hash lock and an atomic rename, so two identical
 *    cache misses do not synthesize twice or publish a half written file.
 *  - The slot count comes from /etc/issabel/piper.conf. When it is "auto" the
 *    engine sizes the pool from MemTotal and nproc, never from free memory.
 */

class issabelTtsException extends Exception
{
}

class issabelTtsEngine
{
    const DEFAULT_VOICE = 'es_MX-claude-high.onnx';

    private static $voiceMap = array(
        'es' => 'es_MX-claude-high.onnx',
        'en' => 'en_US-ljspeech-medium.onnx',
        'pt' => 'pt_BR-faber-medium.onnx',
        'br' => 'pt_BR-faber-medium.onnx',
        'fr' => 'fr_FR-siwis-medium.onnx',
        'it' => 'it_IT-paola-medium.onnx',
        'de' => 'de_DE-thorsten-medium.onnx',
    );

    private $agi;
    private $cfg;
    private static $amportal = null;
    private static $catalog = null;

    public function __construct($agi = null)
    {
        $this->agi = $agi;
        $this->cfg = self::loadConfig();
    }

    /**
     * Synthesize text into one or more playable files.
     *
     * Options:
     *   engine      piper (default), flite, azure, google, elevenlabs, polly, custom
     *   cmd         command configured for the engine (optional for piper)
     *   params_raw  raw JSON string as stored in tts_engines.ttsengine_template
     *   gui         0 stream, 1 return one final file (recordings module)
     *   chunk       maximum characters per fragment (0 disables chunking)
     *   speed       Piper length scale factor
     *
     * When $onFile is a callable it is invoked with each generated file right
     * before the next one is synthesized, so the caller can stream it without
     * waiting for the whole text. Returning true from the callback stops the
     * process (used for DTMF interrupts).
     */
    public function synthesize($text, $opts = array(), $onFile = null)
    {
        $engine     = isset($opts['engine']) && $opts['engine'] !== '' ? $opts['engine'] : 'piper';
        $cmd        = isset($opts['cmd']) ? (string)$opts['cmd'] : '';
        $rawParams  = isset($opts['params_raw']) ? (string)$opts['params_raw'] : '';
        $gui        = !empty($opts['gui']);
        $speed      = isset($opts['speed']) && is_numeric($opts['speed']) ? (float)$opts['speed'] : 1.0;
        $chunkLimit = isset($opts['chunk']) ? (int)$opts['chunk'] : ($engine === 'piper' ? 150 : 0);

        if ($cmd === '' && $engine === 'piper') {
            $cmd = $this->cfg['piper'];
        }
        if ($speed < 0.5) {
            $speed = 0.5;
        } elseif ($speed > 2.0) {
            $speed = 2.0;
        }

        $params = json_decode($rawParams, true);
        if (!is_array($params)) {
            $params = array();
        }

        $text = $this->normalizeText($text);
        if ($text === '') {
            throw new issabelTtsException('No text passed for synthesis');
        }

        $format = $this->formatFor($engine);
        $ext    = $format['ext'];
        $rate   = $format['rate'];

        $finalHash = md5($text . '|' . $engine . '|' . $rawParams);
        $finalPath = $this->soundsDir() . '/' . $engine . '-tts-' . $finalHash . '.' . $ext;

        $files = array();

        if ($chunkLimit > 0) {
            if ($gui && is_file($finalPath)) {
                return array($finalPath);
            }
            $chunks = $this->splitText($text, $chunkLimit);
            foreach ($chunks as $chunk) {
                $file = $this->chunkFile($engine, $chunk, $cmd, $params, $speed, $ext, $rate);
                $files[] = $file;
                if ($onFile !== null && call_user_func($onFile, $file)) {
                    break;
                }
            }
            if ($gui) {
                if (!$files) {
                    throw new issabelTtsException('No audio was generated');
                }
                $this->concatenate($files, $finalPath, $ext);
                $this->writeSidecar($finalPath, $text);
                return array($finalPath);
            }
            return $files;
        }

        if (!is_file($finalPath)) {
            $this->finalFile($engine, $text, $cmd, $params, $speed, $ext, $rate, $finalPath, $rawParams);
        }
        if ($gui) {
            $this->writeSidecar($finalPath, $text);
        } elseif ($onFile !== null) {
            call_user_func($onFile, $finalPath);
        }
        return array($finalPath);
    }

    /* ------------------------------------------------------------------ *
     * Generation
     * ------------------------------------------------------------------ */

    private function chunkFile($engine, $chunk, $cmd, $params, $speed, $ext, $rate)
    {
        $voice = ($engine === 'piper') ? $this->voiceFor($params) : '';
        $hash  = md5($chunk . '|' . $engine . '|' . $voice . '|' . $speed . '|' . $rate);
        $path  = $this->cacheDir() . '/' . $engine . '-' . $hash . '.' . $ext;
        if (is_file($path)) {
            return $path;
        }

        $lock = $this->lock($this->locksDir() . '/locks/' . $hash . '.lock', 60);
        if ($lock === null) {
            throw new issabelTtsException('Timed out waiting for a synthesis lock');
        }
        try {
            if (is_file($path)) {
                return $path;
            }
            $slot = null;
            if ($this->usesSlotPool($engine, $params)) {
                $slot = $this->acquireSlot();
            }
            try {
                $tmp = $this->tempPath($this->cacheDir(), $ext);
                $produced = $this->generate($engine, $chunk, $cmd, $params, $speed, $ext, $rate, $tmp);
                if (!@rename($produced, $path)) {
                    @unlink($produced);
                    throw new issabelTtsException('Cannot publish synthesized audio');
                }
            } finally {
                if ($slot !== null) {
                    flock($slot, LOCK_UN);
                    fclose($slot);
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $path;
    }

    private function finalFile($engine, $text, $cmd, $params, $speed, $ext, $rate, $finalPath, $rawParams)
    {
        $dir = dirname($finalPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $hash = md5($text . '|' . $engine . '|' . $rawParams);
        $lock = $this->lock($this->locksDir() . '/locks/' . $hash . '.lock', 120);
        if ($lock === null) {
            throw new issabelTtsException('Timed out waiting for a synthesis lock');
        }
        try {
            if (is_file($finalPath)) {
                return;
            }
            $slot = null;
            if ($this->usesSlotPool($engine, $params)) {
                $slot = $this->acquireSlot();
            }
            try {
                $tmp = $this->tempPath($dir, $ext);
                $produced = $this->generate($engine, $text, $cmd, $params, $speed, $ext, $rate, $tmp);
                if (!@rename($produced, $finalPath)) {
                    @unlink($produced);
                    throw new issabelTtsException('Cannot publish synthesized audio');
                }
            } finally {
                if ($slot !== null) {
                    flock($slot, LOCK_UN);
                    fclose($slot);
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function generate($engine, $text, $cmd, $params, $speed, $ext, $rate, $base)
    {
        switch ($engine) {
            case 'piper':
                return $this->generatePiper($text, $params, $speed, $rate, $base);
            case 'flite':
                return $this->generateFlite($text, $cmd, $rate, $base);
            case 'custom':
                // {OUTPUTFILE} keeps the historical meaning: a path without
                // extension, so the command can append the format it produces.
                $base = preg_replace('/\.[^.\/]+$/', '', $base);
                return $this->generateCustom($text, $cmd, $ext, $base);
            default:
                return $this->generateCloud($engine, $text, $cmd, $params, $ext, $rate, $base);
        }
    }

    private function generatePiper($text, $params, $speed, $rate, $base)
    {
        $voice = $this->voiceFor($params);
        $model = $this->cfg['voice_dir'] . '/' . $voice;
        if (!is_readable($model)) {
            throw new issabelTtsException('Piper voice model not found: ' . $model);
        }
        if (!is_executable($this->cfg['piper'])) {
            throw new issabelTtsException('Piper is not installed: ' . $this->cfg['piper']);
        }

        $wav = $this->tempPath($this->cacheDir(), 'wav');
        $args = array($this->cfg['piper'], '--quiet', '--model', $model, '--output_file', $wav);
        if ($speed != 1.0) {
            $args[] = '--length_scale';
            $args[] = sprintf('%.3f', 1.0 / $speed);
        }
        $result = $this->run($args, $text);
        if ($result[0] !== 0 || !is_file($wav)) {
            @unlink($wav);
            throw new issabelTtsException('Piper failed: ' . trim($result[1]));
        }
        $this->sox($wav, $rate, $base);
        @unlink($wav);
        return $base;
    }

    private function generateFlite($text, $cmd, $rate, $base)
    {
        $textfile = $this->tempPath($this->cacheDir(), 'txt');
        $wav = $this->tempPath($this->cacheDir(), 'wav');
        file_put_contents($textfile, $text);
        $command = $cmd . ' -f ' . escapeshellarg($textfile) . ' -o ' . escapeshellarg($wav);
        exec($command . ' 2>&1', $output, $rc);
        @unlink($textfile);
        if ($rc !== 0 || !is_file($wav)) {
            @unlink($wav);
            throw new issabelTtsException('flite failed: ' . implode("\n", $output));
        }
        $this->sox($wav, $rate, $base);
        @unlink($wav);
        return $base;
    }

    private function generateCustom($text, $cmd, $ext, $base)
    {
        $command = str_replace(
            array('{TEXT}', '{OUTPUTFILE}'),
            array(escapeshellarg($text), escapeshellarg($base)),
            $cmd
        );
        exec($command . ' 2>&1', $output, $rc);
        if ($rc !== 0) {
            throw new issabelTtsException('custom engine failed: ' . implode("\n", $output));
        }
        $candidates = array($base . '.' . $ext, $base);
        foreach ($candidates as $file) {
            if (is_file($file)) {
                return $file;
            }
        }
        throw new issabelTtsException('custom engine did not create the output file');
    }

    private function generateCloud($engine, $text, $cmd, $params, $ext, $rate, $base)
    {
        if ($cmd === '') {
            throw new issabelTtsException('No command configured for the ' . $engine . ' engine');
        }

        $args = '';
        foreach ($params as $key => $value) {
            if (is_array($value) || is_object($value)) {
                continue;
            }
            if ($engine === 'polly') {
                $args .= ' --' . $key . '=' . escapeshellarg((string)$value);
            } else {
                $args .= ' --' . $key . ' ' . escapeshellarg((string)$value);
            }
        }

        switch ($engine) {
            case 'polly':
                $command = $cmd
                    . ' --mp3=' . escapeshellarg($base . '.mp3')
                    . ' --text=' . escapeshellarg($text)
                    . ' --wav=' . escapeshellarg($base)
                    . $args;
                $produced = $base . '.wav';
                break;
            case 'azure':
            case 'google':
            case 'elevenlabs':
                $command = $cmd
                    . ' --text ' . escapeshellarg($text)
                    . ' --file ' . escapeshellarg($base)
                    . $args;
                $produced = $base . '.' . $ext;
                break;
            default:
                throw new issabelTtsException('Unsupported TTS engine: ' . $engine);
        }

        exec($command . ' 2>&1', $output, $rc);
        if ($rc !== 0 || !is_file($produced)) {
            throw new issabelTtsException($engine . ' failed: ' . implode("\n", $output));
        }

        if ($engine === 'polly') {
            $this->sox($produced, $rate, $base);
            @unlink($produced);
            return $base;
        }
        return $produced;
    }

    /* ------------------------------------------------------------------ *
     * Helpers
     * ------------------------------------------------------------------ */

    private function normalizeText($text)
    {
        $text = (string)$text;
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }

    private function splitText($text, $limit)
    {
        $text = trim($text);
        if ($text === '') {
            return array();
        }
        if (!preg_match('/[.,?!:;]$/u', $text)) {
            $text .= '.';
        }
        $chunks = array();
        $length = mb_strlen($text, 'UTF-8');
        $start  = 0;
        while ($start < $length) {
            $window = mb_substr($text, $start, $limit, 'UTF-8');
            $take   = mb_strlen($window, 'UTF-8');
            if ($start + $take < $length) {
                $cut = -1;
                foreach (array('.', ',', '?', '!', ':', ';') as $mark) {
                    $pos = mb_strrpos($window, $mark, 0, 'UTF-8');
                    if ($pos !== false && $pos > $cut) {
                        $cut = $pos;
                    }
                }
                if ($cut >= 0) {
                    $take = $cut + 1;
                } else {
                    $pos = mb_strrpos($window, ' ', 0, 'UTF-8');
                    $take = ($pos !== false && $pos > 0) ? $pos + 1 : $limit;
                }
            }
            $chunk = trim(mb_substr($text, $start, $take, 'UTF-8'));
            if ($chunk !== '') {
                $chunks[] = $chunk;
            }
            $start += $take;
        }
        return $chunks;
    }

    private function voiceFor($params)
    {
        $voice = (isset($params['voice']) && is_string($params['voice'])) ? basename($params['voice']) : '';
        if ($voice !== '') {
            $voice = $this->withExtension($voice);
            if (is_readable($this->cfg['voice_dir'] . '/' . $voice)) {
                return $voice;
            }
            $this->log('Piper voice model not found: ' . $voice . '; trying lang/default');
            $voice = '';
        }

        if ($voice === '') {
            $lang = (isset($params['lang']) && is_string($params['lang'])) ? $params['lang'] : '';
            $voice = $this->voiceForLang($lang);
        }
        if ($voice === '') {
            $default = $this->cfg['default_lang'];
            if (strtolower((string)$default) === 'auto' || $default === '') {
                $default = $this->guiLanguage();
            }
            $voice = $this->voiceForLang($default);
        }
        if ($voice === '') {
            $voice = $this->voiceForLang('en-US');
        }
        if ($voice === '') {
            $voice = $this->cfg['default_voice'];
        }

        $voice = $this->withExtension($voice);

        // A missing model must not leave the call in silence. Try the default
        // voice and log the problem; the caller still gets a clear error if the
        // default is missing too.
        if (!is_readable($this->cfg['voice_dir'] . '/' . $voice)) {
            $fallback = $this->withExtension($this->cfg['default_voice']);
            $this->log('Piper voice model not found: ' . $voice . '; falling back to ' . $fallback);
            $voice = $fallback;
        }

        return $voice;
    }

    private function withExtension($voice)
    {
        $voice = (string)$voice;
        if ($voice === '') {
            return '';
        }
        return substr($voice, -5) === '.onnx' ? $voice : $voice . '.onnx';
    }

    private function voiceForLang($lang)
    {
        $lang = strtolower(trim((string)$lang));
        if ($lang === '' || $lang === 'auto') {
            return '';
        }
        $lang = str_replace('_', '-', $lang);

        $catalog = self::loadCatalog();
        $voices = isset($catalog['voices']) && is_array($catalog['voices']) ? $catalog['voices'] : array();
        $aliases = isset($catalog['aliases']) && is_array($catalog['aliases']) ? $catalog['aliases'] : array();

        foreach ($voices as $code => $voice) {
            if (strtolower($code) === $lang && !empty($voice['file'])) {
                return basename($voice['file']);
            }
        }
        foreach ($aliases as $alias => $code) {
            if (strtolower($alias) === $lang && isset($voices[$code]['file'])) {
                return basename($voices[$code]['file']);
            }
        }

        $prefix = substr($lang, 0, 2);
        if ($prefix !== '') {
            foreach ($aliases as $alias => $code) {
                if (strtolower($alias) === $prefix && isset($voices[$code]['file'])) {
                    return basename($voices[$code]['file']);
                }
            }
            foreach ($voices as $code => $voice) {
                if (strtolower(substr($code, 0, 2)) === $prefix && !empty($voice['file'])) {
                    return basename($voice['file']);
                }
            }
            if (isset(self::$voiceMap[$prefix])) {
                return self::$voiceMap[$prefix];
            }
        }

        return '';
    }

    /**
     * System/GUI language from the legacy settings database. The AGI runs as
     * asterisk and the file is world-readable, so this does not need a web
     * session or a writable configuration file. When it is unavailable the
     * caller falls back to DEFAULT_VOICE.
     */
    private function guiLanguage()
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $settings_db = '/var/www/db/settings.db';
        if (is_readable($settings_db) && class_exists('PDO')) {
            try {
                $pdo = new PDO('sqlite:' . $settings_db);
                $stmt = $pdo->query("SELECT value FROM settings WHERE key = 'language' LIMIT 1");
                $lang = $stmt ? $stmt->fetchColumn() : '';
                if (!empty($lang)) {
                    $cache = $lang;
                    return $cache;
                }
            } catch (Exception $e) {
                // Fall through to the default voice.
            }
        }
        $cache = '';
        return $cache;
    }

    private static function loadCatalog()
    {
        if (self::$catalog !== null) {
            return self::$catalog;
        }

        $fallback = array(
            'aliases' => array(
                'en' => 'en-US', 'en-GB' => 'en-US',
                'es' => 'es-MX', 'es-ES' => 'es-MX',
                'pt' => 'pt-BR', 'pt-PT' => 'pt-BR', 'br' => 'pt-BR',
                'fr' => 'fr-FR', 'it' => 'it-IT', 'de' => 'de-DE',
            ),
            'voices' => array(
                'es-MX' => array('file' => 'es_MX-claude-high.onnx'),
                'en-US' => array('file' => 'en_US-ljspeech-medium.onnx'),
                'pt-BR' => array('file' => 'pt_BR-faber-medium.onnx'),
                'fr-FR' => array('file' => 'fr_FR-siwis-medium.onnx'),
                'it-IT' => array('file' => 'it_IT-paola-medium.onnx'),
                'de-DE' => array('file' => 'de_DE-thorsten-medium.onnx'),
            ),
        );

        $path = '/etc/issabel/piper.conf';
        $catalog = '';
        if (is_readable($path)) {
            $ini = @parse_ini_file($path, true, INI_SCANNER_RAW);
            if (is_array($ini) && isset($ini['paths']['catalog']) && $ini['paths']['catalog'] !== '') {
                $catalog = $ini['paths']['catalog'];
            }
        }
        if ($catalog === '') {
            $catalog = '/usr/share/issabel-piper/voices.json';
        }

        if (is_readable($catalog)) {
            $json = @file_get_contents($catalog);
            if ($json !== false) {
                $data = json_decode($json, true);
                if (is_array($data) && !empty($data['voices']) && is_array($data['voices'])) {
                    if (!isset($data['aliases']) || !is_array($data['aliases'])) {
                        $data['aliases'] = $fallback['aliases'];
                    }
                    self::$catalog = $data;
                    return self::$catalog;
                }
            }
        }

        self::$catalog = $fallback;
        return self::$catalog;
    }

    private function log($message)
    {
        if ($this->agi !== null && method_exists($this->agi, 'verbose')) {
            @$this->agi->verbose($message, 3);
        } else {
            @error_log('issabel-tts: ' . $message);
        }
    }

    private function formatFor($engine)
    {
        if ($engine === 'azure' || $engine === 'google') {
            return array('ext' => 'alaw', 'rate' => 8000);
        }
        if ($engine === 'elevenlabs') {
            return array('ext' => 'ulaw', 'rate' => 8000);
        }
        if ($this->agi !== null) {
            return $this->agi->nativeFormat();
        }
        return array('ext' => 'sln', 'rate' => 8000);
    }

    private function run(array $args, $stdin = null)
    {
        $descriptors = array(
            0 => array('pipe', 'r'),
            1 => array('file', '/dev/null', 'w'),
            2 => array('pipe', 'w'),
        );
        $proc = @proc_open($args, $descriptors, $pipes);
        if (!is_resource($proc)) {
            return array(-1, 'cannot execute ' . $args[0]);
        }
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $rc = proc_close($proc);
        return array($rc, $stderr);
    }

    private function sox($input, $rate, $output)
    {
        $args = array($this->cfg['sox'], $input, '-q', '-r', (string)$rate, '-t', 'raw', $output);
        $result = $this->run($args);
        if ($result[0] !== 0 || !is_file($output)) {
            @unlink($output);
            throw new issabelTtsException('SoX failed: ' . trim($result[1]));
        }
    }

    private function concatenate($files, $finalPath, $ext)
    {
        $dir = dirname($finalPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $tmp = $this->tempPath($dir, $ext);
        $out = @fopen($tmp, 'wb');
        if (!$out) {
            throw new issabelTtsException('Cannot create ' . $tmp);
        }
        foreach ($files as $file) {
            $in = @fopen($file, 'rb');
            if (!$in) {
                fclose($out);
                @unlink($tmp);
                throw new issabelTtsException('Cannot read ' . $file);
            }
            stream_copy_to_stream($in, $out);
            fclose($in);
        }
        fclose($out);
        if (!@rename($tmp, $finalPath)) {
            @unlink($tmp);
            throw new issabelTtsException('Cannot publish ' . $finalPath);
        }
    }

    private function writeSidecar($finalPath, $text)
    {
        $txt = preg_replace('/\.[^.]+$/', '', $finalPath) . '.txt';
        @file_put_contents($txt, $text);
    }

    private function tempPath($dir, $ext)
    {
        return $dir . '/.tmp-' . getmypid() . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
    }

    private function soundsDir()
    {
        $dir = $this->astDataDir() . '/sounds/custom/tts';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    private function cacheDir()
    {
        $dir = $this->cfg['cache_dir'];
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            $dir = $this->locksDir() . '/cache';
            if (!is_dir($dir)) {
                @mkdir($dir, 0770, true);
            }
        }
        $this->maybePrune($dir);
        return $dir;
    }

    private function locksDir()
    {
        $dir = $this->cfg['locks_dir'];
        foreach (array($dir, $dir . '/locks', $dir . '/slots') as $sub) {
            if (!is_dir($sub)) {
                @mkdir($sub, 0770, true);
            }
        }
        return $dir;
    }

    private function lock($path, $timeout)
    {
        $fh = @fopen($path, 'c');
        if (!$fh) {
            return null;
        }
        $deadline = microtime(true) + $timeout;
        while (!flock($fh, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                fclose($fh);
                return null;
            }
            usleep(100000);
        }
        return $fh;
    }

    private function usesSlotPool($engine, $params)
    {
        if ($engine === 'piper') {
            return true;
        }
        return ($engine === 'custom' && !empty($params['pool']));
    }

    private function acquireSlot()
    {
        $slots = $this->slotCount();
        $dir   = $this->locksDir() . '/slots';
        $min   = $this->cfg['pool_min_available_mb'];
        $deadline = microtime(true) + $this->cfg['pool_timeout'];

        while (true) {
            if ($this->memAvailableMb() < $min) {
                throw new issabelTtsException('Not enough free memory to run Piper');
            }
            for ($i = 0; $i < $slots; $i++) {
                $fh = @fopen($dir . '/slot' . $i, 'c');
                if (!$fh) {
                    continue;
                }
                if (flock($fh, LOCK_EX | LOCK_NB)) {
                    return $fh;
                }
                fclose($fh);
            }
            if (microtime(true) >= $deadline) {
                throw new issabelTtsException('Timed out waiting for a Piper slot');
            }
            usleep(200000);
        }
    }

    private function slotCount()
    {
        $configured = $this->cfg['pool_slots'];
        if (is_numeric($configured) && (int)$configured > 0) {
            return (int)$configured;
        }

        $mem     = $this->memTotalMb();
        $reserve = (int)$this->cfg['pool_reserve_mb'];
        $percent = (int)floor($mem * 0.20);
        if ($percent > $reserve) {
            $reserve = $percent;
        }
        $perSlot = max(64, (int)$this->cfg['pool_per_slot_mb']);
        $byRam   = (int)floor(max(0, $mem - $reserve) / $perSlot);

        $cpus = $this->cpuCount();
        $cap  = min((int)$this->cfg['pool_max_slots'], max(1, $cpus - 1));
        $slots = min($byRam, $cap);
        return max(1, $slots);
    }

    private function memTotalMb()
    {
        $kb = $this->meminfo('MemTotal');
        $mb = (int)floor($kb / 1024);

        // Containers report the host memory in /proc/meminfo. Honour the
        // cgroup limit when it is lower than what the host says.
        foreach (array('/sys/fs/cgroup/memory.max', '/sys/fs/cgroup/memory/memory.limit_in_bytes') as $limitFile) {
            if (is_readable($limitFile)) {
                $limit = trim(@file_get_contents($limitFile));
                if ($limit !== '' && $limit !== 'max' && is_numeric($limit)) {
                    $limitMb = (int)floor(((float)$limit) / 1048576);
                    if ($limitMb > 0 && $limitMb < $mb) {
                        $mb = $limitMb;
                    }
                }
                break;
            }
        }
        return $mb;
    }

    private function memAvailableMb()
    {
        return (int)floor($this->meminfo('MemAvailable') / 1024);
    }

    private function meminfo($key)
    {
        $data = @file_get_contents('/proc/meminfo');
        if ($data !== false && preg_match('/^' . preg_quote($key, '/') . ':\s+(\d+)/m', $data, $m)) {
            return (int)$m[1];
        }
        return 0;
    }

    private function cpuCount()
    {
        $data = @file_get_contents('/proc/cpuinfo');
        if ($data !== false) {
            $count = preg_match_all('/^processor\s*:/m', $data);
            if ($count > 0) {
                return $count;
            }
        }
        return 1;
    }

    private function maybePrune($dir)
    {
        $days = (int)$this->cfg['retention_days'];
        if ($days <= 0) {
            return;
        }
        $marker = $this->locksDir() . '/.last-prune';
        if (is_file($marker) && (time() - filemtime($marker)) < 3600) {
            return;
        }
        @touch($marker);
        exec('find ' . escapeshellarg($dir) . ' -maxdepth 1 -type f -mtime +' . $days . ' -delete 2>/dev/null');
        exec('find ' . escapeshellarg($dir) . ' -maxdepth 1 -name ".tmp-*" -mtime +1 -delete 2>/dev/null');
        exec('find ' . escapeshellarg($this->locksDir() . '/locks') . ' -maxdepth 1 -type f -mtime +' . $days . ' -delete 2>/dev/null');
    }

    private function astDataDir()
    {
        if (self::$amportal === null) {
            self::$amportal = array();
            foreach (array('/etc/amportal.conf', '/etc/asterisk/amportal.conf') as $file) {
                if (!is_readable($file)) {
                    continue;
                }
                foreach (file($file) as $line) {
                    if (preg_match('/^\s*([A-Za-z0-9_]+)\s*=\s*(.*?)\s*$/', $line, $m)) {
                        self::$amportal[$m[1]] = $m[2];
                    }
                }
                break;
            }
        }
        return !empty(self::$amportal['ASTDATADIR']) ? self::$amportal['ASTDATADIR'] : '/var/lib/asterisk';
    }

    /* ------------------------------------------------------------------ *
     * Configuration
     * ------------------------------------------------------------------ */

    private static function loadConfig()
    {
        $cfg = array(
            'piper'                  => '/usr/bin/piper',
            'sox'                    => '/usr/bin/sox',
            'voice_dir'              => '/usr/share/issabel-piper/voices',
            'catalog'                => '/usr/share/issabel-piper/voices.json',
            'cache_dir'              => '/var/lib/asterisk/issabel-tts/cache',
            'locks_dir'              => '/var/lib/asterisk/issabel-tts',
            'default_voice'          => self::DEFAULT_VOICE,
            'default_lang'           => 'auto',
            'pool_slots'             => 'auto',
            'pool_timeout'           => 20,
            'pool_per_slot_mb'       => 256,
            'pool_reserve_mb'        => 1536,
            'pool_max_slots'         => 4,
            'pool_min_available_mb'  => 384,
            'retention_days'         => 30,
        );

        $file = '/etc/issabel/piper.conf';
        if (is_readable($file)) {
            $ini = @parse_ini_file($file, true);
            if (is_array($ini)) {
                foreach (array('piper', 'sox', 'voice_dir', 'catalog', 'cache_dir', 'locks_dir', 'default_voice') as $key) {
                    if (isset($ini['paths'][$key]) && $ini['paths'][$key] !== '') {
                        $cfg[$key] = $ini['paths'][$key];
                    }
                }
                if (isset($ini['voice']['default_lang']) && $ini['voice']['default_lang'] !== '') {
                    $cfg['default_lang'] = $ini['voice']['default_lang'];
                }
                $map = array(
                    'slots'             => 'pool_slots',
                    'timeout'           => 'pool_timeout',
                    'per_slot_mb'       => 'pool_per_slot_mb',
                    'reserve_mb'        => 'pool_reserve_mb',
                    'max_slots'         => 'pool_max_slots',
                    'min_available_mb'  => 'pool_min_available_mb',
                );
                foreach ($map as $iniKey => $cfgKey) {
                    if (isset($ini['pool'][$iniKey]) && $ini['pool'][$iniKey] !== '') {
                        $cfg[$cfgKey] = $ini['pool'][$iniKey];
                    }
                }
                if (isset($ini['cache']['retention_days']) && $ini['cache']['retention_days'] !== '') {
                    $cfg['retention_days'] = (int)$ini['cache']['retention_days'];
                }
            }
        }

        foreach (array('pool_timeout', 'pool_per_slot_mb', 'pool_reserve_mb', 'pool_max_slots', 'pool_min_available_mb') as $key) {
            $cfg[$key] = (int)$cfg[$key];
        }
        return $cfg;
    }
}
