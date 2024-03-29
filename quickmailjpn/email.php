<?php //$Id: email.php 4 2012-04-28 18:19:08Z yama $
/**
 * email.php - Used by Quickmail for sending emails to users enrolled in a specific course.
 *      Calls email.hmtl at the end.
 *
 * @author Mark Nielsen (co-maintained by Wen Hao Chuang)
 * @special thanks for Neil Streeter to provide patches for GROUPS
 * @package quickmail
 **/

require_once '../../config.php';
require_once $CFG->libdir . '/blocklib.php';
require_once $CFG->dirroot . '/blocks/quickmailjpn/locallib.php';
require_once('email_form.php');
use ver2\quickmailjpn\quickmailjpn as qm;
use ver2\quickmailjpn\util;

/* @var $DB moodle_database */
/* @var $CFG object */
/* @var $USER object */
/* @var $PAGE moodle_page */
/* @var $OUTPUT core_renderer */

$id         = required_param('id', PARAM_INT);  // course ID
$instanceid = optional_param('instanceid', 0, PARAM_INT);
$action     = optional_param('action', '', PARAM_ALPHA);

$PAGE->set_url('/blocks/quickmailjpn/email.php',array('id' => $id,'instanceid'=>$instanceid));

$instance = new stdClass();

if (!$course = $DB->get_record('course', array('id' => $id))) {
    print_error('Course ID was incorrect');
}

require_login($course->id);
$context = context_course::instance($course->id);

if ($instanceid) {
    $instance = $DB->get_record('block_instances', array('id' => $instanceid));
} else {
    if ($quickmailjpnblock = $DB->get_record('block', array('name' => 'quickmailjpn'))) {
        $instance = $DB->get_record('block_instances', array('blockid' => $quickmailjpnblock->id, 'pageid' => $course->id));
    }
}

/// This block of code ensures that QuickmailJPN will run
///     whether it is in the course or not
if (empty($instance)) {
    $groupmode = $course->groupmode;
    if (has_capability('block/quickmailjpn:cansend', context_block::instance($instanceid))) {
        $haspermission = true;
    } else {
        $haspermission = false;
    }
} else {
    // create a quickmailjpn block instance
    /* @var $quickmailjpn block_quickmailjpn */
    $quickmailjpn = block_instance('quickmailjpn', $instance);

    $groupmode     = $quickmailjpn->groupmode();
    $haspermission = $quickmailjpn->check_permission();
}

if (!$haspermission) {
	print_error('errornopermission', 'block_quickmailjpn');
}

$groups = '';
if (groups_get_course_groupmode($course)) {
	$groups = groups_get_course_group($course, true);
}
if (!$courseusers = get_users_by_capability($context, 'moodle/grade:view', 'u.*',
		'u.lastname, u.firstname', '', '', $groups, '', false)) {
	print_error('errornocourseusers', 'block_quickmailjpn');
}
$editor_options = array(
    'trusttext' => false,
    'subdirs' => 1,
    'maxfiles' => EDITOR_UNLIMITED_FILES,
    'accepted_types' => '*',
    'context' => $context
);

if ($action == 'view') {
    // viewing an old email.  Hitting the db and puting it into the object $form
    $emailid = required_param('emailid', PARAM_INT);
    $form = $DB->get_record('block_quickmailjpn_log', array('id' => $emailid), '*', MUST_EXIST);
    $form->mailto = explode(',', $form->mailto); // convert mailto back to an array
    $form->id = $id;
    $form->messageformat =  editors_get_preferred_format();
    $form = file_prepare_standard_editor(
        $form, 'message', $editor_options, $context, 'block_quickmailjpn', $type, $form->id
    );
} else if ($form = data_submitted()) {   // data was submitted to be mailed
    confirm_sesskey();

    if (!empty($form->cancel)) {
        // cancel button was hit...
        redirect("$CFG->wwwroot/course/view.php?id=$course->id");
    }

    // make sure the user didn't miss anything
    if (!isset($form->mailto)) {
        $form->error = get_string('toerror', 'block_quickmailjpn');
    } else if (!$form->mailfrom) {
        $form->error = get_string('fromerror', 'block_quickmailjpn');
    } else if (!$form->subject) {
        $form->error = get_string('subjecterror', 'block_quickmailjpn');
    } else if (!$form->message_editor) {
        $form->error = get_string('messageerror', 'block_quickmailjpn');
    }

    // no errors, then email
    if(!isset($form->error)) {
        $mailedto = array(); // holds all the userid of successful emails

        // 携帯メールフィルタで拒否されるのを防止するため、
        // ヘッダの From: には常に noreply を使用し、
        // 差出人は Reply-To: に指定する

        // From:
        /*  if (!empty($CFG->block_quickmailjpn_email)) {
            $mailfrom = $CFG->block_quickmailjpn_email;
        } elseif (!empty($CFG->noreplyaddress)) {
            $mailfrom = $CFG->noreplyaddress;
        } else {
            $hostname = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST']);
            $mailfrom = 'noreply@' . $hostname;
        }*/
        $mailfrom = $form->mailfrom;
        // Reply-To:
        if (validate_email($form->mailfrom)) {
            $mailreplyto = $form->mailfrom;
        } elseif ($mobileemail = $DB->get_field(qm::TABLE_USERS, 'mobileemail',
                  array('userid' => $USER->id, 'mobileemailstatus' => QuickMailJPN_State::CONFIRMED))) {
            $mailreplyto = $mobileemail;
        } elseif (!$USER->emailstop) {
            $mailreplyto = $USER->email;
        } else {
            $mailreplyto = null;
        }

        if (!validate_email($form->mailfrom)) {
            $mailfromname = $form->mailfrom;
        }  else {
            $mailfromname = fullname($USER);
        }

        $form->format = $form->message_editor['format'];
        $form->message = $form->message_editor['text'];
        $form->attachment = attachment_names($form->attachments);
        $form->messageWithSigAndAttach = "";
        $table = 'log';


        foreach ($form->mailto as $userid) {
            $mailedto[] = $userid;
        }
        // prepare an object for the insert_record function
        $log = new stdClass;
        $log->courseid   = $course->id;
        $log->userid     = $USER->id;
        $log->mailto     = implode(',', $mailedto);
        $log->subject    = $form->subject;
        $log->message    = $form->message;
        $log->mailfrom   = $form->mailfrom;
        $log->attachment   = $form->attachment;
        $log->format   = $form->format;
        $log->timesent   = time();

        $form->id = $DB->insert_record('block_quickmailjpn_log', $log);
        if (!$form->id) {
            print_error('Email not logged.');
        }

        $form = file_postupdate_standard_editor(
            $form, 'message', $editor_options, $context, 'block_quickmailjpn', $table, $form->id
        );
        $log->message  = $form->message;
        $log->format   = $form->format;
        $log->id = $form->id;
        $DB->update_record('block_quickmailjpn_' . $table, $log);

        // An instance id is needed before storing the file repository /////////
        file_save_draft_area_files(
            $form->attachments, $context->id, 'block_quickmailjpn', 'attachment_' . $table, $form->id, $editor_options
        );
        // Prepare html content of message /////////////////////////////////
        $form->message = file_rewrite_pluginfile_urls($form->message, 'pluginfile.php', $context->id, 'block_quickmailjpn', $table, $form->id, $editor_options);

        $form->messageWithSigAndAttach = $form->message;
        // Append links to attachments, if any /////////////////////////////
        $form->messageWithSigAndAttach .= process_attachments(
            $context, $log, $table, $form->id
        );

        $messagetext = format_text_email($form->messageWithSigAndAttach, $form->format);

        // HTML
        $options = array('filter' => false);
        $messagehtml = format_text($form->messageWithSigAndAttach, $form->format, $options);

        //$mail = get_jmailer();

        // run through each user id and send a copy of the email to him/her
        // not sending 1 email with CC to all user ids because emails were required to be kept private
        foreach ($form->mailto as $userid) {
            // 携帯メールはMoodle本体のメールとは別機能なのでブロックしない
            //if (!$courseusers[$userid]->emailstop) {

        	$userinfo = $DB->get_record(qm::TABLE_USERS, array('userid' => $userid));
        	$email = $userinfo->mobileemail;
        	$status = $userinfo->mobileemailstatus;

            if (empty($email)) {
                // 未設定
                continue;
            }

            if ($status != QuickMailJPN_State::CONFIRMED) {
                // 未チェック
                continue;
            }

            //send e-mail by JPHPMailer via PHPMailer


            $mail = get_jmailer();
            /*$mail->SMTPAutoTLS = false;*/
            $mail->addTo($email, fullname($courseusers[$userid]));
            /*$mail->setFrom('jiafp.cs@adtis.com.cn', $mailfromname);*/
            $mail->setFrom($mailfrom, $mailfromname);
            if ($mailreplyto) {
                $mail->addReplyTo($mailreplyto, $mailfromname);
            }
            $mail->setSubject($form->subject);
            $bodyText  = 'Date: ' . date('Y/m/d') . "\n\n<br/>";
            $bodyText .= 'From: ' . fullname($USER) . "\n\n<br/>";
            $bodyText .= 'Subject: ' . $form->subject . "\n\n<br/>";
            $bodyText .= 'Message: ' . $messagehtml . "\n\n<br/>";
            $mail->setHtmlBody($bodyText);
            $mailresult = $mail->send();
            $log->failuserids = [];
            // checking for errors, if there is an error, store the name
            if (!$mailresult || (string) $mailresult == 'emailstop') {
                $log->failuserids[] = $userid;
                $form->error = get_string('emailfailerror', 'block_quickmailjpn');
                $form->usersfail['emailfail'][] = $courseusers[$userid]->lastname.', '.$courseusers[$userid]->firstname;
            } else {
                // success
                $mailedto[] = $userid;
            }
        }

        $log->failuserids = count($log->failuserids)>0?implode(',', $log->failuserids):"";
        $log->id = $form->id;
        $DB->update_record('block_quickmailjpn_log', $log);

        if (!empty($form->sendmecopy)) {
        	$mail = get_jmailer();
        	$mail->addTo($mailreplyto, $mailfromname);
        	$mail->setFrom($mailfrom, $mailfromname);
        	$mail->setSubject($form->subject);
            $bodyText  = 'Date: ' . date('Y/m/d') . "\n\n<br/>";
            $bodyText .= 'From: ' . fullname($USER) . "\n\n<br/>";
            $bodyText .= 'Subject: ' . $form->subject . "\n\n<br/>";
            $bodyText .= 'Message: ' . $messagehtml . "\n\n<br/>";


            $mail->setHtmlBody($bodyText);
            $mailresult = $mail->send();
        }


        if (!isset($form->error)) {  // if no emailing errors, we are done
            // inform of success and continue
            redirect("$CFG->wwwroot/course/view.php?id=$course->id", get_string('successfulemail', 'block_quickmailjpn'));
        }
    }

} else {
    // set them as blank
    $form = new \stdClass();
    $form->subject = $form->message = $form->format = $form->attachment = '';
    $form->mailfrom = $USER->email;
}

/// Create the table object for holding course users in the To section of email.html


$tblStr  = "<table border='0' cellspacing='2' cellpadding='2'>\n";
$tblStr .= "<tr>";
$tblStr .= "<th colspan='2' align='left'>".get_string('select', 'block_quickmailjpn')."</th>";
$tblStr .= "<th>".get_string('name', 'block_quickmailjpn')."</th>";
$tblStr .= "<th>".get_string('mobilephone', 'block_quickmailjpn')."</th>";
$tblStr .= "<th>".get_string('status', 'block_quickmailjpn')."</th>";
$tblStr .= "</tr>\n";

// フルネーム順にソートするために先にフルネームを取得してプロパティ追加
array_walk($courseusers, function ($u) { $u->fullname = fullname($u); });

// 設定に従ってソート
// TODO: 拡張フィールドによるソートにも対応させる
switch ($CFG->block_quickmailjpn_sortorder) {
case 'firstname':
case 'lastname':
case 'fullname':
    $order = $CFG->block_quickmailjpn_sortorder;
    break;
default:
    $order = 'fullname';
}
core_collator::asort_objects_by_property($courseusers, $order, core_collator::SORT_NATURAL);

$i = 0;
foreach ($courseusers as $user) {
    $i++;
    $email = null;
    $status = qm::STATUS_NOT_SET;
    if ($qmuser = qm::get_user($user->id)) {
    	$email = $qmuser->mobileemail;
    	$status = $qmuser->mobileemailstatus;
    }
    if (isset($form->mailto) && in_array($user->id, $form->mailto)) {
        $checked = 'checked="checked"';
    } else {
        $checked = '';
    }
    switch ($status) {
    case QuickMailJPN_State::NOT_SET:
        $str_status = '<font color="blue">'.get_string($status, 'block_quickmailjpn').'</font>';
        break;
    case QuickMailJPN_State::CHECKING:
        $str_status = '<font color="red">'.get_string($status, 'block_quickmailjpn').'</font>';
        break;
    case QuickMailJPN_State::CONFIRMED:
        $str_status = '<font color="green">'.get_string($status, 'block_quickmailjpn').'</font>';
        break;
    default:
        $str_status = '';
    }
    if ($status == QuickMailJPN_State::CONFIRMED) {
        $disabled = '';
    } else {
        $disabled = 'disabled="disabled"';
    }
    $tblStr .= "<tr>\n";
    $tblStr .= "<td width='30'><input type='checkbox' $checked $disabled id='mailto$i' value='$user->id' name='mailto[]' /></td>\n";
    $tblStr .= "<td width='30'><label for='mailto$i'>$i</label></td>";
    $tblStr .= "<td><label for='mailto$i'>".htmlspecialchars($user->fullname)."</label></td>";
    $tblStr .= "<td><label for='mailto$i'>".htmlspecialchars($email)."</label></td>";
    $tblStr .= "<td><label for='mailto$i'>$str_status</label></td>";
    $tblStr .= "</tr>\n";
}

$tblStr .= "</table>\n";

// set up some strings
$readonly        = '';
$strchooseafile  = get_string('chooseafile', 'block_quickmailjpn');
$strquickmailjpn = get_string('blockname', 'block_quickmailjpn');

/// Header setup
$PAGE->set_title($course->fullname.': '.$strquickmailjpn);
$PAGE->set_heading($course->fullname);
$PAGE->navbar->add($strquickmailjpn);

echo $OUTPUT->header();

// print the email form START
echo $OUTPUT->heading($strquickmailjpn);

// error printing
if (isset($form->error)) {
    echo $OUTPUT->notification($form->error);
    if (isset($form->usersfail)) {
        $errorstring = '';

        if (isset($form->usersfail['emailfail'])) {
            $errorstring .= get_string('emailfail', 'block_quickmailjpn').'<br />';
            foreach($form->usersfail['emailfail'] as $user) {
                $errorstring .= $user.'<br />';
            }
        }

        if (isset($form->usersfail['emailstop'])) {
            $errorstring .= get_string('emailstop', 'block_quickmailjpn').'<br />';
            foreach($form->usersfail['emailstop'] as $user) {
                $errorstring .= $user.'<br />';
            }
        }
        notice($errorstring, "$CFG->wwwroot/course/view.php?id=$course->id", $course);
    }
}

$currenttab = 'compose';
include($CFG->dirroot.'/blocks/quickmailjpn/tabs.php');

echo groups_print_course_menu($course, new \moodle_url($PAGE->url, array(
        'id' => $id,
		'instanceid' => $instanceid
)));
$message = s($form->message);

$mform = new email_form(null, array(
    'editor_options' => $editor_options,
    'id' => $id,
    'instanceid' => $instanceid,
    'tblStr'=>$tblStr,
    'mailfrom'=>$form->mailfrom,
));
if ($action == 'view' && empty($form->attachments)) {
    $attachid = file_get_submitted_draft_itemid('attachment');
    file_prepare_draft_area(
        $attachid, $context->id, 'block_quickmailjpn', 'attachment_' . 'log', $form->id
    );
    $form->attachments = $attachid;
}
$mform->set_data($form);
echo $OUTPUT->box_start('center');
require($CFG->dirroot.'/blocks/quickmailjpn/email.html');
$mform->display();
echo $OUTPUT->box_end();

echo $OUTPUT->footer($course);
