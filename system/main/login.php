<?php

/**
 * main function for login
 * @return string
 */
function login_main() {
  global $q, $config, $user;
  
  $txt = "";
 
  if ($t = readConf("admin_message")) addErrorMessage($t);
  if ($t = readVar("message")) addInfoMessage($t);
  
  // Sicherstellen, dass keiner eingelogt ist!
  if (!userLoggedIn()) {
    if ($t = readVar("login_message")) addInfoMessage($t, true);
    $form = new CTForm("LoginForm", "validateLogin", "Login");
    $form->setHeader(t("login.headline"), t("please.fill.following.fields"));
    $form->addField("email", "", "INPUT_REQUIRED", t("email.or.username"), true);
    $form->addField("password", "", "PASSWORD", t("password"));
    // TODO: when is this false?
    if (!readConf("show_remember_me") || readConf("show_remember_me") == 1) $form->addField("rememberMe", "", "CHECKBOX", t("remember.me"));
    $form->addButton(t("login"), "ok");
    
    if (readVar("newpwd") && $email = readVar("email")) {
      $res = db_query("SELECT COUNT(*) c FROM {cdb_person} 
                       WHERE email=':email' AND archiv_yn=0",
                       array(':email' => $email))
             ->fetch();
      if ($res->c == 0) {
        $txt .= '
        <div class="alert alert-error"><p>Bitte ein g&uuml;ltige EMail-Adresse angeben, 
            an die das neue Passwort gesendet werden kann! 
            Diese Adresse muss im System schon eingerichtet sein.
            <p>Falls die E-Mail-Adresse schon eingerichtet sein sollte, 
            wende Dich bitte an <a href="' . readConf("site_mail") . '">' . readConf("site_mail") . '</a>.</div>';
      }
      else {
        $newpwd = random_string(8);
        // TODO: not needed to send passwords by email, use one time login instead
        $scrambled_password = scramble_password($newpwd);
        db_query("UPDATE {cdb_person} 
                  SET password='" . $scrambled_password . "' 
                  WHERE email=:email",
                  array(':email' => $email));
        
        $content = "<h3>" . t('hello') . "!</h3>
          <p>" . t('new.password.requested.for.xxx.is.yyy', $email, $newpwd) . "</p>";
        churchcore_systemmail($email, "[" . readConf('site_name') . "] Neues Passwort", $content, true, 1);
        churchcore_sendMails(1);
        $txt .= '<div class="alert alert-info">Hinweis: Ein neues Passwort wurde nun an <i>' . $_GET["email"] .
             '</i> gesendet.</div>';
        ct_log("Neues Passwort angefordert: $email", 2, "-1", "login");
      }
    }
    // access through externale tools through GET and additional direct
    // TODO: is it important to look in post only?
    else if ($email = readVar("email", false, $_POST) 
             && $password = readVar("password", false, $_POST) 
             && $directTool = readVar("directtool", false, $_POST)) {
      include_once (CHURCHCORE . "/churchcore_db.php");
      
      $res = db_query("SELECT * FROM {cdb_person} 
                       WHERE email=:email AND active_yn=1 AND archiv_yn=0", 
                       array (":email" => $email))
                       ->fetch();
      if (!$res) {
        drupal_json_output(jsend()->fail(t('email.unknown')));
      }
      else if (user_check_password($password, $res)) {
        login_user($res);
        ct_log("Login durch Direct-Tool $directTool mit $email", 2, "-1", "login");
        drupal_json_output(jsend()->success());
      }
      else
        drupal_json_output(jsend()->fail(t('wrong.password')));
      return;
    }
    // check for login with one time login string in url
    // e.g. http://localhost:8888/bootstrap/?q=profile&loginstr=123&id=8
    else if ($loginString = readVar("loginstr") && $id = readVar('id')) {
      // delete login strings older then 14 days
      db_query("DELETE FROM {cc_loginstr} 
                WHERE DATEDIFF( current_date, create_date ) > 13");
      
      $res = db_query("SELECT * FROM {cc_loginstr} 
                       WHERE loginstr=:loginstr AND person_id=:id", 
                       array (":loginstr" => $loginstr, 
                              ":id" => $id
                       ))->fetch();
      if (!$res) {
        $txt .= '<div class="alert alert-info">' . t('login.string.too.old') . '</div>';
      }
      else {
        // delete current loginStr to prevent misuse
        $res = db_query("DELETE FROM {cc_loginstr} 
                         WHERE loginstr=:loginstr AND person_id=:id", 
                         array (":loginstr" => $loginString, 
                                ":id" => $i,
                         ));
        ct_log("Login User $id erfolgreich mit loginstr ", 2, "-1", "login");
        $res = churchcore_getPersonById($id);
        login_user($res);
      }
    }
    
    $txt .= $form->render();
    $txt .= '<script>jQuery("#newpwd").click(function(k,a) {
         if (confirm("' . t('want.to.receive.new.password') . '")) {
           window.location.href="?newpwd=true&email="+jQuery("#LoginForm_email").val()+"&q=' . $q . '";
            }
          });</script>';
  }
  // someone is already logged in 
  else {
    // switch to another family user (same email)
    if ($familyId = readVar("family_id")) {
      if (isset($_SESSION["family"][$familyId])) {
        // logout_current_user();
        login_user($_SESSION["family"][$familyId]);
        $txt .= '<div class="alert alert-info">Ummelden erfolgreich! Du arbeitest nun mit der Berechtigung von ' .
             $_SESSION["user"]->vorname . ' ' . $_SESSION["user"]->name . '.</div>';
      }
      else
        $txt .= "<div class='alert alert-info'>Ummelden zu Id: $familyId hat nicht funktioniert, Session ist leer!</div>";
    }
    else {
      $txt .= '<div class="alert alert-info"><i>Hinweis:</i> Du bist angemeldet als ' . $_SESSION["user"]->vorname .
           ', weiter geht es <a href="?q=home">hier</a>!</div>';
    }
  }
  return $txt;
}

/**
 * validate login form
 * TODO: is there a difference between returning false or null?
 * @param CTForm $form
 * @return bool or null?
 */
function validateLogin($form) {
  
  $res = db_query("SELECT * FROM {cdb_person} 
                   WHERE (email=:email OR cmsuserid=:email OR id=:email) AND archiv_yn=0", 
                   array (":email" => $form->fields["email"]->getValue()));
  
  $accountInactive = false;
  $tooMuchLogins = false;
  $wrongEmail = true;
  // foreach because family emails may be used for more then one user
  foreach ($res as $u) {
    $wrongEmail = false;
    if ($u->loginerrorcount > 6) $tooMuchLogins = true;
    else {
      if (user_check_password($form->fields["password"]->getValue(), $u)) {
        if (!$u->active_yn) $accountInactive = true;
        else {
          login_user($u, $form->fields["rememberMe"] ? $form->fields["rememberMe"]->getValue() : false);
          return null;
        }
      }
      else {
        db_query("UPDATE {cdb_person} SET loginerrorcount=loginerrorcount+1 
                  WHERE id=:id",
                  array(':id' => $u->id));
      }
    }
  }
  
  if ($wrongEmail) {
    $form->fields["email"]->setError(t('email.or.username.unknown'));
    ct_log("Login failed: wrong email " . $form->fields["email"]->getValue(), 2, "-1", "login");
    return false;
  }
  else if ($accountInactive) {
    $form->fields["email"]->setError(t('account.was.locked'));
    ct_log("Login failed: Access locked " . $form->fields["email"]->getValue(), 1, "-1", "login");
    return false;
  }
  else if ($tooMuchLogins) {
    $form->fields["email"]->setError(t('account.was.locked.cause.of.to.many.trials'));
    ct_log("Login failed: To many trials " . $form->fields["email"]->getValue(), 1, "-1", "login");
    return false;
  }
  else {
    $form->fields["password"]->setError(t('wrong.password') . ' <a href="#" id="newpwd">' . t('forgot.password') .
         '</a>');
    ct_log("Login failed: " . $form->fields["email"]->getValue() . " wrong password", 2, "-1", "login");
    return false;
  }
}

/**
 * 
 * @param array $u userdata
 * @param bool $rember_me
 * @return NULL
 */
function login_user($u, $rember_me = false) {
  global $q, $q_orig;
  
  if (empty($u->id)) {
    addErrorMessage(t("login.error.no.id.given"));
    return null;
  }
  $_SESSION["email"] = $u->email;
  
  if (!$u->cmsuserid) {
    $u->cmsuserid = "$u->vorname $u->name [" . $u->id . "]";
    
    db_query("UPDATE {cdb_person} 
              SET cmsuserid=:cmsuserid 
              WHERE id=:id",
              array(':cmsuserid' => $u->cmsuserid,
                    ':id' => $u->id,
              ));
  }
  if ($u->loginstr) {
    db_query("UPDATE {cdb_person} SET loginstr=NULL WHERE id=$u->id");
  }
  
  $u->auth = getUserAuthorization($u->id);
  $_SESSION["user"] = $u;
  
  // TODO: make time configurable
  // login is valid for 6 days
   $cookieExpireTime = time() + 60 * 60 * 24 * 6;
  
  setcookie("RememberMe", $rember_me,  $cookieExpireTime);
  $_SESSION["sessionid"] = random_string();
  setcookie("CC_SessionId", $_SESSION["sessionid"],  $cookieExpireTime);
  $dt = new DateTime();
  
  db_query("UPDATE {cdb_person} SET lastlogin=NOW(), loginerrorcount=0 WHERE id=:id", array(':id' => $u->id));
  // db_query("DELETE FROM {cc_session} WHERE person_id=".$u->id." AND hostname='".$_SERVER["HTTP_HOST"]."'");
  db_query("DELETE FROM {cc_session} WHERE datediff(NOW(), datum)>7");
  db_query("INSERT INTO {cc_session} (person_id, session, hostname, datum) 
            VALUES (:id, :session, :host, :date)",
            array( ':id' => $u->id,
                   ':session' => $_SESSION["sessionid"],
                   ':host' => $_SERVER["HTTP_HOST"],
                   ':date' => $dt->format('Y-m-d H:i:s'),
            ));
  
  if ($u->email) {
    // look for family users with the same email
    $res = db_query("SELECT * FROM {cdb_person} 
                     WHERE email=:email AND archiv_yn=0", 
                     array (":email" => $u->email));
    $family = array();
    $count = 0;
    foreach ($res as $p) {
      if ($p->id != $u->id) $family[$p->id] = $p;
      $count++;
      if ($count > 15) break; //no family should have more then 15 users
    }
    if (count($family)) $_SESSION["family"] = $family;
  }
  
  ct_log("Login succeed: $u->email with " . readVar('HTTP_USER_AGENT', "Unkown Browser", $_SERVER), 2, -1, "login");
  
  // on switching family login dont forward to login again
  if ($q != $q_orig) {
    header("Location: ?q=$q_orig");
  }
  else if ($q == "login") header("Location: ?q=" . readConf("site_startpage", "home"));
}

?>
