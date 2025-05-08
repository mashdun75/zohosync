<?php
class Zoho_API {
    private $client_id     = 'YOUR_CLIENT_ID';
    private $client_secret = 'YOUR_CLIENT_SECRET';
    private $token_option  = 'gf_zoho_tokens';

    private function get_tokens() {
        $t = get_option($this->token_option);
        if (!$t) wp_die('Zoho tokens missing.');
        return $t;
    }

    public function get_access_token() {
        return $this->get_tokens()['access_token'];
    }

    public function refresh_token() {
        $t = $this->get_tokens();
        $r = wp_remote_post('https://accounts.zoho.com/oauth/v2/token',[ 'body'=>[
            'refresh_token'=>$t['refresh_token'],
            'client_id'=>$this->client_id,
            'client_secret'=>$this->client_secret,
            'grant_type'=>'refresh_token',
        ]]);
        $new = json_decode(wp_remote_retrieve_body($r),true);
        if(isset($new['access_token'])){
            update_option($this->token_option,$new);
            return $new['access_token'];
        }
        wp_die('Zoho refresh failed.');
    }

    public function request($method,$endpoint,$body=null) {
        $token = $this->get_access_token();
        $args = [ 'headers'=>['Authorization'=>"Zoho-oauthtoken $token"] ];
        if($body){
            $args['headers']['Content-Type']='application/json';
            $args['body']=wp_json_encode(['data'=>[$body]]);
        }
        $url="https://www.zohoapis.com/crm/v2/$endpoint";
        $res=wp_remote_request($url,array_merge($args,['method'=>$method]));
        if(wp_remote_retrieve_response_code($res)===401){
            $token=$this->refresh_token();
            $args['headers']['Authorization']="Zoho-oauthtoken $token";
            $res=wp_remote_request($url,array_merge($args,['method'=>$method]));
        }
        return json_decode(wp_remote_retrieve_body($res),true);
    }

    public function upload_attachment($module,$id,$path){
        $token=$this->get_access_token();
        $url="https://www.zohoapis.com/crm/v2/{$module}/{$id}/Attachments";
        $h=['Authorization'=>"Zoho-oauthtoken $token"];
        $b=['file'=>curl_file_create($path)];
        $r=wp_remote_post($url,['headers'=>$h,'body'=>$b,'timeout'=>60]);
        if(wp_remote_retrieve_response_code($r)===401){
            $token=$this->refresh_token();
            $h['Authorization']="Zoho-oauthtoken $token";
            $r=wp_remote_post($url,['headers'=>$h,'body'=>$b,'timeout'=>60]);
        }
        return json_decode(wp_remote_retrieve_body($r),true);
    }

    public function register_webhook($config){
        return $this->request('POST','settings/webhooks',$config);
    }

    public function remove_webhook($id){
        return $this->request('DELETE',"settings/webhooks/$id");
    }
}