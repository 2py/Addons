<?php

if (!defined('APPLICATION'))
   exit();
/**
 * @copyright Copyright 2008, 2009 Vanilla Forums Inc.
 * @license http://www.opensource.org/licenses/gpl-2.0.php GPLv2
 */
// Define the plugin:
$PluginInfo['StopForumSpam'] = array(
    'Name' => 'Stop Forum Spam',
    'Description' => "Integrates the spammer blacklist from stopforumspam.com",
    'Version' => '1.0b',
    'RequiredApplications' => array('Vanilla' => '2.0.18b1'),
    'Author' => 'Todd Burry',
    'AuthorEmail' => 'todd@vanillaforums.com',
    'AuthorUrl' => 'http://www.vanillaforums.org/profile/todd'
);

class StopForumSpamPlugin extends Gdn_Plugin {

   /// Properties ///
   /// Methods ///

   public static function Check(&$Data) {
      // Make the request.
      $Get = array();


     
      if (isset($Data['IPAddress'])) {
         $AddIP = TRUE;
         // Don't check against the localhost.
         foreach (array(
            '127.0.0.1/0',
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16') as $LocalCIDR) {

            if (Gdn_Statistics::CIDRCheck($Data['IPAddress'], $LocalCIDR)) {
               $AddIP = FALSE;
               break;
            }
         }
         if ($AddIP)
            $Get['ip'] = $Data['IPAddress'];
      }
      if (isset($Data['Username'])) {
         $Get['username'] = $Data['Username'];
      }
      if (isset($Data['Email'])) {
         $Get['email'] = $Data['Email'];
      }

      if (empty($Get))
         return FALSE;

      $Get['f'] = 'json';

      $Url = "http://www.stopforumspam.com/api?" . http_build_query($Get);

      $Curl = curl_init();
      curl_setopt($Curl, CURLOPT_URL, $Url);
      curl_setopt($Curl, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($Curl, CURLOPT_TIMEOUT, 4);
      curl_setopt($Curl, CURLOPT_FAILONERROR, 1);
      $ResultString = curl_exec($Curl);
      curl_close($Curl);

      if ($ResultString) {
         $Result = json_decode($ResultString, TRUE);

         $IPFrequency = GetValueR('ip.frequency', $Result, 0);
         $EmailFrequency = GetValueR('email.frequency', $Result, 0);

         // Ban ip addresses appearing more than threshold.
         if ($IPFrequency > 5) {
            $Result = TRUE;;
         } elseif ($EmailFrequency > 20) {
            $Result = TRUE;
         }

         if ($Result) {
            $Data['_Meta']['IP Frequency'] = $IPFrequency;
            $Data['_Meta']['Email Frequency'] = $EmailFrequency;
         }
         return $Result;
      }

      return FALSE;
   }

   public function Setup() {
      $this->Structure();
   }

   public function Structure() {
      // Get a user for operations.
      $UserID = Gdn::SQL()->GetWhere('User', array('Name' => 'StopForumSpam', 'Admin' => 2))->Value('UserID');

      if (!$UserID) {
         $UserID = Gdn::SQL()->Insert('User', array(
            'Name' => 'StopForumSpam',
            'Password' => RandomString('20'),
            'HashMethod' => 'Random',
            'Email' => 'stopforumspam@domain.com',
            'DateInserted' => Gdn_Format::ToDateTime(),
            'Admin' => '2'
         ));
      }
      SaveToConfig('Plugins.StopForumSpam.UserID', $UserID);
   }

   public function UserID() {
      return C('Plugins.StopForumSpam.UserID', NULL);
   }

   /// Event Handlers ///

   public function Base_CheckSpam_Handler($Sender, $Args) {
      // Don't check for spam if another plugin has already determined it is.
      if ($Sender->EventArguments['IsSpam'])
         return;

      $RecordType = $Args['RecordType'];
      $Data =& $Args['Data'];

      $Result = FALSE;
      switch ($RecordType) {
         case 'Registration':
            $Result = self::Check($Data);
            if ($Result) {
               $Data['Log_InsertUserID'] = $this->UserID();
               $Data['RecordIPAddress'] = Gdn::Request()->IpAddress();
            }
            break;
         case 'Comment':
         case 'Discussion':
         case 'Activity':
//            $Result = $this->CheckTest($RecordType, $Data) || $this->CheckStopForumSpam($RecordType, $Data) || $this->CheckAkismet($RecordType, $Data);
            break;
      }
      $Sender->EventArguments['IsSpam'] = $Result;
   }
}