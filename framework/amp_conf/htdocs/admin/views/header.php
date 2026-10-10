<?php
$version			= get_framework_version();
$version_tag		= '?load_version=' . urlencode($version);
if ($amp_conf['FORCE_JS_CSS_IMG_DOWNLOAD']) {
	$this_time_append	= '.' . time();
	$version_tag 		.= $this_time_append;
} else {
	$this_time_append = '';
}

if (isset($_COOKIE['lang'])) {
    $partes = preg_split("/_/",$_COOKIE['lang']);
    $printlang = $partes[0];
} else {
    $printlang="en";
}
//html head
$html = '';
$html .= '<!DOCTYPE html>';

if ($use_popover_css) {
    $html .= '<html lang="'.$printlang.'">';
} else {
    $html .= '<html lang="'.$printlang.'" class="has-navbar-fixed-top">';
}

$html .= '<head>';
$html .= '<title>'
		. (isset($title) ? __($title) : $amp_conf['BRAND_TITLE'])
		. '</title>';

// Issabel 6 "modern" theme bridge (D12). When the framework theme is present,
// issabelPBX adopts the Issabel brand and follows its light/dark mode. The
// preference lives in localStorage under the same origin as the framework GUI.
$iss_modern_bridge = is_dir('/var/www/html/themes/modern');
if ($iss_modern_bridge) {
	$html .= '<script>(function(){function a(m){try{document.documentElement.setAttribute("data-theme",(m==="auto")?(window.matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light"):m);}catch(e){}}var k="issabel-theme";a(localStorage.getItem(k)||"auto");window.addEventListener("storage",function(e){if(e.key===k)a(e.newValue||"auto");});window.addEventListener("message",function(e){if(e.data&&e.data.type==="iss-theme")a(e.data.theme);});})();</script>';
}

$html .= '<meta http-equiv="Content-Type" content="text/html;charset=utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
		. '<meta name="robots" content="noindex" />'
		. '<link rel="shortcut icon" href="' . $amp_conf['BRAND_IMAGE_FAVICON'] . '">';


$html .= '<link rel="stylesheet" href="assets/css/bulma.min.css">';
$html .= '<link rel="stylesheet" href="assets/css/bulma-tooltip.min.css">';
$html .= '<link rel="stylesheet" href="assets/css/bulma-checkbox.css">';
$html .= '<link rel="stylesheet" href="assets/css/bulma-switch.min.css">';
$html .= '<link rel="stylesheet" href="assets/css/animate.min.css">';
$html .= '<link href="assets/css/font-awesome.min.css" rel="stylesheet" type="text/css"/>';

//css
$mainstyle_css      = $amp_conf['BRAND_CSS_ALT_MAINSTYLE'] 
                       ? $amp_conf['BRAND_CSS_ALT_MAINSTYLE'] 
                       : 'assets/css/mainstyle.css';
$framework_css = ($amp_conf['DISABLE_CSS_AUTOGEN'] || !file_exists($amp_conf['mainstyle_css_generated'])) ? $mainstyle_css : $amp_conf['mainstyle_css_generated'];
$css_ver = '.' . filectime($framework_css);
$html .= '<link href="' . $framework_css.$version_tag.$css_ver . '" rel="stylesheet" type="text/css">';

// Issabel 6 "modern" theme bridge (must load AFTER Bulma + mainstyle)
if ($iss_modern_bridge) {
	$html .= '<link href="assets/css/modern-bridge.css" rel="stylesheet" type="text/css"/>';
}

$html .= '<link href="assets/css/fonts.css" rel="stylesheet" type="text/css"/>';

$html .= '<link href="assets/css/chosen.css" rel="stylesheet" type="text/css"/>';

//add the popover.css stylesheet if we are displaying a popover to override mainstyle.css styling
if ($use_popover_css) {
	$popover_css = $amp_conf['BRAND_CSS_ALT_POPOVER'] ? $amp_conf['BRAND_CSS_ALT_POPOVER'] : 'assets/css/popover.css';
	$html .= '<link href="' . $popover_css.$version_tag . '" rel="stylesheet" type="text/css"/>';
}

//include rtl stylesheet if using a rtl langauge
if (isset($_COOKIE['lang']) && in_array($_COOKIE['lang'], array('he_IL'))) {
	$html .= '<link href="assets/css/mainstyle-rtl.css" rel="stylesheet" type="text/css" />';
}
// Insert a custom CSS sheet if specified (this can change what is in the main CSS)
if ($amp_conf['BRAND_CSS_CUSTOM']) {
	$html .= '<link href="' . $amp_conf['BRAND_CSS_CUSTOM'] 
			. $version_tag . '" rel="stylesheet" type="text/css"/>';
}

$html .= '<script src="assets/js/sweetalert2.min.js"></script>';
$html .= '<link rel="stylesheet" href="assets/css/sweetalert2.min.css" type="text/css"/>';

// Issabel 6: jQuery local (offline). No external CDN.
$html .= '<script src="assets/js/jquery-' . $amp_conf['JQUERY_VER'] . '.min.js"></script>';
		
$html .= '<script src="assets/js/chosen.jquery.js"></script>';
$html .= '<script src="assets/js/unpoly.min.js"></script>';
$html .= '<script src="assets/js/jquery-migrate-3.4.0.js"></script>';
$html .= '<script src="assets/js/loadingoverlay.js"></script>';
$html .= '<script src="assets/js/jquery.dirty.js"></script>';
$html .= '<link rel="stylesheet" href="assets/css/unpoly.min.css" type="text/css"/>';
$html .= '</head>';

//open body
$html .= '<body>';

$html .= '<div id="page">';//open page

//add script warning
$html .= '<noscript><div class="attention">'
		. __('WARNING: Javascript is disabled in your browser. '
		. 'The IssabelPBX administration interface requires Javascript to run properly. '
		. 'Please enable javascript or switch to another  browser that supports it.') 
		. '</div></noscript>';

echo $html;
