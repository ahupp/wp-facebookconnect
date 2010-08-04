<?php

// via
// http://planetozh.com/blog/2008/07/what-plugin-coders-must-know-about-wordpress-26/
$root = dirname(dirname(dirname(dirname(__FILE__))));
if (file_exists($root.'/wp-load.php')) {
  // WP 2.6
  require_once($root.'/wp-load.php');
} else {
  // Before 2.6
  require_once($root.'/wp-config.php');
}

require_once($root . '/wp-includes/registration.php');

if (!class_exists('Facebook')) {
  // prevent fatal due to multiple inclusions.
  require_once('facebook-client/facebook.php');
}

function _fbc_make_client() {
  return new Facebook(array(
    'appId' => get_option(FBC_APP_ID_OPTION),
    'secret' => get_option(FBC_APP_SECRET_OPTION),
    'cookie' => true
  ));
}

/*
 * Get the facebook client object for easy access.
 */
function fbc_facebook_client() {
  static $facebook = null;
  if ($facebook === null) {
    $facebook = _fbc_make_client();
  }
  return $facebook;
}

/**
  provides an api client without a user session.
 */
function fbc_anon_api_client() {
  static $client = null;
  if ($client != null) {
    return $client;
  }
  $client = _fbc_make_client();
  $client->setSession(null);
  return $client;
}

function fbc_get_displayname($userinfo) {
  if (empty($userinfo['name'])) {
    // i18n-able
    return _(FBC_ANONYMOUS_DISPLAYNAME);
  } else {
    return $userinfo['name'];
  }
}

function render_fb_profile_pic($user) {
  return <<<EOF
    <div class="avatar avatar-32">
    <fb:profile-pic uid="$user" facebook-logo="true" size="square"></fb:profile-pic>
    </div>
EOF;
}


function render_fbconnect_button($onlogin=null) {
  if ($onlogin !== null) {
    $onlogin_str = ' onlogin="'. $onlogin .'" ';
  } else {
    $onlogin_str = '';
  }
  return <<<EOF
<div class="dark">
  <fb:login-button perms="email,offline_access" size="large"
background="white" length="short" $onlogin_str>
  </fb:login-button>
</div>
EOF;

}

function get_wpuid_by_fbuid($fbuid) {
  global $wpdb;
  $sql = "SELECT user_id FROM $wpdb->usermeta WHERE meta_key = 'fbuid' AND meta_value = %s";
  $res = $wpdb->get_results($wpdb->prepare($sql, $fbuid), ARRAY_A);
  if ($res) {
    return $res['user_id'];
  } else {
    return 0;
  }
}

define('FBC_ERROR_NO_FB_SESSION', -2);
define('FBC_ERROR_USERNAME_EXISTS', -1);

function fbc_login_if_necessary($allow_link=false) {
  $fbuid = fbc_facebook_client()->getUser();

  if ($fbuid) {
    $wpuid = fbc_fbuser_to_wpuser($fbuid);
    if (!$wpuid) {
      // There is no wp user associated w/ this fbuid

      $user = wp_get_current_user();
      $wpuid = $user->ID;
      if ($wpuid && $allow_link) {
        // User already has a wordpress account, link to this facebook account
        update_usermeta($wpuid, 'fbuid', "$fbuid");
      } else {
        // Create a new wordpress account
        $wpuid = fbc_insert_user($fbuid);
        if ($wpuid === FBC_ERROR_USERNAME_EXISTS) {
          return FBC_ERROR_USERNAME_EXISTS;
        }
      }

    } else {
      // Already have a linked wordpress account, fall through and set
      // login cookie
    }

    wp_set_auth_cookie($wpuid, true, false);

    return $fbuid;
  } else {
    return FBC_ERROR_NO_FB_SESSION;
  }
}

function get_user_by_meta($meta_key, $meta_value) {
  global $wpdb;
  $sql = "SELECT user_id FROM $wpdb->usermeta WHERE meta_key = '%s' AND meta_value = '%s'";
  return $wpdb->get_var($wpdb->prepare($sql, $meta_key, $meta_value));
}

function fbc_fbuser_to_wpuser($fbuid) {
  return get_user_by_meta('fbuid', $fbuid);
}

function fbc_userinfo_to_wp_user($userinfo) {
  $info = array(
    'display_name' => fbc_get_displayname($userinfo),
    'user_url' => $userinfo['link'],
    'first_name' => $userinfo['first_name'],
    'last_name' => $userinfo['last_name'],
    'user_email' => $userinfo['email']
  );
  if (isset($userinfo['email']) {
    $info['user_email'] = $userinfo['email'];
  }
  return $info;
}

function fbc_userinfo_keys() {
  return array('name',
    'first_name',
    'last_name',
    'link',
    'email');
}


function fbc_get_user_access_token($wpid) {
  $sessionStr = get_usermeta($wpid, 'fbsession');
  if ($sessionStr[0] == 'a') {
    return $sessionStr;
  }
  $session = json_decode($sessionStr,true);
  return $session['access_token'];
}

function fbc_insert_user($fbuid) {

  $userinfo = fbc_facebook_client()->api($fbuid,'GET',
                array('fields' => fbc_userinfo_keys()));
  if ($userinfo === null) {
    error_log('wp-fbconnect: empty query result for user ' . $fbuid);
  }

  $fbusername = 'fb' . $fbuid;
  if (username_exists($fbusername)) {
    return FBC_ERROR_USERNAME_EXISTS;
  }

  $userdata = fbc_userinfo_to_wp_user($userinfo);
  $userdata += array(
    'user_pass' => wp_generate_password(),

    /*
      WP3.0 requires an unique email address for new accounts.  We might
      not have one, so give it a unique and identifiably fake address.
    */
    'user_email' => $fbusername.'@wp-fbconnect.fake',
    'user_login' => $fbusername,
    /*
      In the event this blog is configured to setup new users as
      admins, don't apply that to fbconnect users.
     */
    'role' => 'subscriber'
  );

  $wpuid = wp_insert_user($userdata);
  // $wpuid might be an instance of WP_Error
  if($wpuid && is_integer($wpuid)) {
    update_usermeta($wpuid, 'fbuid', "$fbuid");
  }

  return $wpuid;
}

function fbc_save_user_session($wpid, $session) {
  $sessionStr = json_encode($session);
  update_usermeta($wpid, 'fbsession', $sessionStr);
}

