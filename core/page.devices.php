<?php
if (!defined('ISSABELPBX_IS_AUTH')) { die('No direct script access allowed'); }
?>
<?php
$devices = core_devices_list();
if($devices===null) $devices=array();
$rnaventries = array();
foreach($devices as $idx=>$data) {
    $midev = core_devices_get($data[0]);
    $tech = isset($midev['tech']) ? $midev['tech'] : '';
    if (isset($midev['tech']) && issabel_devices_pjsip_is_webrtc($midev)) {
        $tech = 'WebRTC';
    }
    $tag = '<span class="tag is-white tagfixed">'.$tech.'</span>';
    $rnaventries[]=array($data[0],$data[1],$tag.'<span class="rnav-id">'.$data[0].'</span>');
}
drawListMenu($rnaventries, $type, $display, $extdisplay);
?>
<div class='content'>
