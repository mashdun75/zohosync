<?php
function gf_zoho_add_settings_menu(){
    add_options_page('Zoho Sync','Zoho Sync','manage_options','gf-zoho-sync','gf_zoho_sync_settings_page');
}
add_action('admin_menu','gf_zoho_add_settings_menu');

function gf_zoho_sync_settings_page(){
    echo '<div class="wrap"><h1>Zoho Sync Settings</h1>';
    // Disconnect
    if(isset($_GET['action'])&&$_GET['action']=='disconnect'){
        delete_option('gf_zoho_tokens');
        echo '<div class="updated"><p>Disconnected.</p></div>';
    }
    // OAuth callback
    if(isset($_GET['code'])){
        gf_zoho_handle_oauth_callback(sanitize_text_field($_GET['code']));
    }
    if(!get_option('gf_zoho_tokens')){
        $url=gf_zoho_get_auth_url();
        echo '<a id="gf-zoho-connect" href="'.esc_url($url).'" class="button button-primary">Connect to Zoho</a>';
    } else {
        echo '<p>✅ Connected</p>';
        $disc=add_query_arg('action','disconnect');
        echo '<p><a href="'.esc_url($disc).'" class="button">Disconnect</a></p>';
    }
    echo '</div>';
}

function gf_zoho_get_auth_url(){
    $cid='YOUR_CLIENT_ID';
    $uri=rawurlencode(admin_url('options-general.php?page=gf-zoho-sync'));
    $scope=rawurlencode('ZohoCRM.modules.ALL');
    return "https://accounts.zoho.com/oauth/v2/auth?scope={$scope}&client_id={$cid}&response_type=code&access_type=offline&redirect_uri={$uri}";
}

function gf_zoho_handle_oauth_callback($code){
    $cid='YOUR_CLIENT_ID';$cs='YOUR_CLIENT_SECRET';
    $redirect=admin_url('options-general.php?page=gf-zoho-sync');
    $r=wp_remote_post('https://accounts.zoho.com/oauth/v2/token',['body'=>[
        'grant_type'=>'authorization_code','client_id'=>$cid,'client_secret'=>$cs,'redirect_uri'=>$redirect,'code'=>$code
    ]]);
    $data=json_decode(wp_remote_retrieve_body($r),true);
    if(!empty($data['access_token'])){
        update_option('gf_zoho_tokens',$data);
        echo '<div class="updated"><p>Connected!</p></div>';
    } else {
        echo '<div class="error"><p>Error: '.esc_html($data['error']??'unknown').'</p></div>';
    }
}