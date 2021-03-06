<?php
if (!defined('APPLICATION')) {
    exit();
}

$PluginInfo['MoarNotificationOptions'] = array(
    'Name' => 'MoarNotificationOptions',
    'Description' => 'Adds Facebook notification',
    'Version' => '1.0',
    'Author' => "Tom Sassen",
    'AuthorEmail' => 'tom.sassen@hotmail.com',
    'MobileFriendly' => TRUE,
    'RequiredApplications' => array('Vanilla' => '2.1')
);

class moarnotificationoptionsPlugin extends Gdn_Plugin {

    public function notificationscontroller_BeforeRender_handler() {
        resetStatus();
    }

    public function resetStatus() {
        if (!gdn::session()->getAttribute("Facebook.Notifications.Uptodate", false)) {
            gdn::userModel()->saveAttribute(gdn::session()->UserID, "Facebook.Notifications.Uptodate", true);
        }
    }

    /**
     * Run when plugin is enabled.
     *
     * @return void.
     */
    public function setup() {
        $this->structure();
    }

    /**
     * Change db structure.
     *
     * @return void.
     */
    public function structure() {
        // New column for sent status of push messages.
        Gdn::structure()
                ->table('Activity')
                ->column('Facebook', 'tinyint(1)', 0)
                ->set();
    }

    /**
     * Extend notifications screen to show additional notification provider.
     *
     * @param ProfileController $sender The calling controller.
     *
     * @return void.
     */
    public function profileController_afterPreferencesDefined_handler($sender) {
        // Add new column to notification preferences.
        foreach ($sender->Preferences as $preferenceGroup => $preferences) {
            foreach ($preferences as $name => $description) {
                $nameParts = explode('.', $name);
                $sender->Preferences[$preferenceGroup]['Facebook.' . $nameParts[1]] = $description;
            }
        }
    }

    /*
     * Break out IFrame Facebook puts you in
     */

    function plugincontroller_redirect_create() {
        $this->resetStatus();
        header("X-Frame-Options: ALLOW-FROM https://facebook.com");
        ?>U wordt doorgestuurd naar het Duivelsei forum. Een ogenblik geduld alstublieft.<script>window.parent.location.href = "<?= Gdn_Url::webRoot(true); ?>";</script><?php
    }

    public function __construct() {
        parent::__construct();
    }

    /**
     * Add Activity to Queue.
     *
     * Ensure that this activity is queued.
     * TODO: check if this causes double activities!
     *
     * @param ActivityModel $sender Instance of the sending class.
     * @param mixed         $args   Event arguments.
     *
     * @return void.
     */
    public function activityModel_beforeCheckPreference_handler($sender, $args) {
        // Check if user wants to be notified of such events.
        if (!$sender->notificationPreference(ActivityModel::getActivityType($args['Data']['ActivityType']), $args['Data']['NotifyUserID'], 'Facebook')) {
            return;
        }

        ActivityModel::$Queue[$args['Data']['NotifyUserID']][$args['Data']['ActivityType']] = [
            $args['Data'],
            $args['Options']
        ];
    }

    /**
     * If user wants to be informed via Facebook, send a ping to Facebook.
     */
    public function activityModel_beforeSave_handler($sender, $args) {
        $notifyUserID = $args['Activity']['NotifyUserID'];
        $activityType = ActivityModel::getActivityType($args['Activity']['ActivityTypeID'])['Name'];
        if (!(gdn::userModel()->getAttribute($notifyUserID, "Facebook.Notifications.Uptodate", true) &&
                $sender->notificationPreference($activityType, $notifyUserID, 'Facebook'))) {
            return;
        }

        // Result will be an "Activity Status" (see class ActivityModel).
        $result = $this->notify($args['Activity']);
        $args['Activity']['Facebook'] = $result;
    }

    /**
     * Send notification with custom notification provider.
     *
     * This function must return one of the "Activity Status" codes defined
     * in ActivityModel.
     * SENT_OK    = successful delivered
     * SENT_ERROR = repeat delivery
     * SENT_FAIL  = fatal error
     *
     * @param object $activity Activity object.
     *
     * @return integer One of the SENT_... constants of ActivityModel.
     */
    private function notify($activity) {
        $notifyUserID = $activity['NotifyUserID'];
        try {
            $app_access = $this->getAppAccessToken();
            $notification_url = $this->getNotificationURL($notifyUserID);
            if ($app_access && $notification_url) {
                $parameters = ["access_token" => $app_access,
                    "href" => "",
                    "template" => strip_tags($activity['Story']) . ". There might be more notifications on the forum."];
                $this->sendToFacebook($notification_url, $parameters);
            }
        } catch (Exception $e) {
            echo $e->getMessage();
            return ActivityModel::SENT_ERROR;
        }
        gdn::userModel()->saveAttribute($notifyUserID, "Facebook.Notifications.Uptodate", false);
        return ActivityModel::SENT_OK;
    }

    private function getAppAccessToken() {
        $app_access = c('Plugins.Facebook.AppAccessToken', false);
        if ($app_access === false) {
            $appid = c('Plugins.Facebook.ApplicationID', false);
            $appsecret = c('Plugins.Facebook.Secret', false);
            if ($appid && $appsecret) {
                $app_access = file_get_contents("https://graph.facebook.com/oauth/access_token?client_id=$appid&client_secret=$appsecret&grant_type=client_credentials");
                $app_access = substr($app_access, strpos($app_access, '=') + 1);
                saveToConfig('Plugins.Facebook.AppAccessToken', $app_access);
            }
        }
        return $app_access;
    }

    private function getNotificationURL($notifyUserID) {
        $user_access_token = val('AccessToken', Gdn::userModel()->getAttribute($notifyUserID, "Facebook", false), false);
        if (!$user_access_token) {
            return false;
        }
        $response = file_get_contents("https://graph.facebook.com/me?fields=id&access_token=$user_access_token");
        $userid = json_decode($response)->id;
        if ($userid) {
            return "https://graph.facebook.com/$userid/notifications";
        } else {
            saveToConfig("error", $response);
        }
        return false;
    }

    private function sendToFacebook($notification_url, $parameters) {
        $ch = curl_init();
//set the url, number of POST vars, POST data
        curl_setopt($ch, CURLOPT_URL, $notification_url);
        curl_setopt($ch, CURLOPT_POST, count($parameters));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $parameters);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

//execute post
        curl_exec($ch);

//close connection
        curl_close($ch);
    }

}
