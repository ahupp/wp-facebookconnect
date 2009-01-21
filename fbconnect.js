
var FBConnect = {

  /*
   * If this value is true alert boxes will be raised when various
   * types of configuration errors are detected.
   */
  debugVerbose : (window.location.href.indexOf('fbc_verbose_debug') != -1),

  initialized : false,

  init : function(api_key, plugin_path,
                  template_bundle_id, home_url,
                  wp_user, app_config) {

    if (!api_key) {
      FBConnect.error("api_key is not set");
    }

    if (!plugin_path) {
      FBConnect.error("plugin path not provided");
    }

    // Check for properly configured template - note this test fails in IE!
    if (this.debugVerbose) {
      var html_tag = document.getElementsByTagName('html').item(0);
      if (html_tag.getAttribute('xmlns:fb') === null) {
        FBConnect.error('xmlns:fb not defined on html tag - check your templates');
      }
    }

    FBConnect.home_url = home_url || "/";

    FBConnect.plugin_path = plugin_path;
    FBConnect.template_bundle_id = template_bundle_id;
    FBConnect.wp_user = wp_user;

    FB.init(api_key, plugin_path + "xd_receiver.php", app_config);

    FBConnect.initialized = true;
  },

  appconfig_reload : {
    reloadIfSessionStateChanged: true
  },

  appconfig_none : {},

  appconfig_ajaxy : {
    ifUserConnected : fbc_onlogin_noauto,
    ifUserNotConnected : fbc_onlogout_noauto
  },

  logout : function() {
    FB.ensureInit(function() {
       FB.Connect.logout();
    });
  },

  redirect_home : function() {
    window.location = FBConnect.home_url;
  },

  /*
   wordpress specific functions
   */
  setup_feedform : function() {

    if (!FBConnect.template_bundle_id) {
      FBConnect.error("no template id provided");
      return;
    }

    var comment_form = ge('commentform');
    if (!comment_form) {
      FBConnect.error('unable to locate id=commentform');
      return;
    }

    var orig_submit = ge('submit');
    if (!orig_submit) {
      FBConnect.error('failed to find comment submit button, maybe it has a new id?');
      return;
    }

    /* This is a bit of a hack. The default theme gives the submit
     button an id of "submit". This causes it to overwrite the
     .submit() function on the form. The solution is to delete the
     submit button and recreate it with a different id. See
     http://jibbering.com/faq/names/ for more info on why this is
     bad.  */

    var subbutton = document.createElement('input');
    subbutton.setAttribute('name', 'fbc_submit_hack');
    subbutton.setAttribute('type', 'submit');

    comment_form.appendChild(subbutton);

    orig_submit.parentNode.replaceChild(subbutton, orig_submit);

    subbutton.onclick = function () {
      return FBConnect.show_comment_feedform();
    };
  },

  show_comment_feedform : function() {

    var template_data = {
        'post-url': window.location.href,
        'post-title': FBConnect.article_title,
        'blog-name': FBConnect.blog_name,
        'blog-url': FBConnect.home_url
    };

    var comment_text = '';

    var commentform = ge('commentform');
    if (commentform) {
      // if this isn't present somethign is seriously wrong
      var comment_box = commentform.comment;
      if (comment_box) {
        comment_text = comment_box.value;
      } else {
        FBConnect.error('unable to locate comment textarea');
        return true;
      }
    } else {
      FBConnect.error('unable to locate comment form, expected id=commentform');
      return true;
    }

    if (comment_text.trim().length === 0) {
      // allow normal submit to complete
      return true;
    }

    var body_general = FBConnect.make_body_general(comment_text);

    FB.Connect.showFeedDialog(FBConnect.template_bundle_id,
                              template_data,
                              null, // template_ids
                              body_general,
                              null, // story_size
                              FB.RequireConnect.promptConnect, // require_connect
                              function() {
                                commentform.submit();
                              });

    // submit handled by showFeedDialog
    return false;

  },

  /*
   * Generates FBML for the body of a newsfeed story.  The story looks like:
   *
   * Sally wrote: "some insightful comment"
   */
  make_body_general : function(comment) {
    var words = comment.split(' ');
    if (words.length > 50) {
      words = words.slice(0, 50);
      words.push('...');
    }
    var comment_clip = words.join(' ');
    return "<fb:pronoun capitalize=\'true\' useyou=\'false\' uid=\'actor\' /> wrote: \"" + comment_clip + "\"";
  },

  error : function(msg) {
    if (FBConnect.debugVerbose) {
      var emsg = 'Error: ' + msg;
      alert(emsg);
    }
  }

};

// end FBConnect

function fbc_onlogout_noauto() {
  fbc_set_visibility_by_class('fbc_hide_on_login', '');
  fbc_set_visibility_by_class('fbc_hide_on_logout', 'none');
}


function fbc_onlogin_noauto() {

  fbc_set_visibility_by_class('fbc_hide_on_login', 'none');
  fbc_set_visibility_by_class('fbc_hide_on_logout', '');
  FBConnect.setup_feedform();
}

function fbc_set_visibility_by_class(cls, vis) {
  var res = document.getElementsByClassName(cls);
  for(var i = 0; i < res.length; ++i) {
    res[i].style.visibility = vis;
  }
}


function ge(elem) {
  return document.getElementById(elem);
}
