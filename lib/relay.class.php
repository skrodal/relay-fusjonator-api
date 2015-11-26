<?php
	/**
	 *
	 *
	 * @author Simon Skrodal
	 * @since  November 2015
	 */

	require_once($BASE . '/lib/db/relaysqlconnection.class.php');

	// Some calls take a long while so increase timeout limit from def. 30
	set_time_limit(300);    // 5 mins
	// Have experienced fatal error - allowed memory size of 128M exhausted - thus increase
	ini_set('memory_limit', '350M');

	class Relay {
		protected $config;
		private $DEBUG = false;
		private $relaySQL;

		function __construct($config) {
			$this->config   = $config;
			$this->relaySQL = new RelaySQLConnection($config);
		}

		########################################################
		# FUNCTIONS ACCESSIBLE BY ROUTES
		########################################################

		public function getRelayVersion() {
			$versionResponse = $this->relaySQL->query("SELECT * FROM tblVersion")[0];

			return $versionResponse['versValue'];
		}

		/**
		 * READ-ONLY
		 *
		 * Check existence of OLD USERNAME in Relay's database. If it is found, also check for
		 * existence of new username.
		 *
		 * Expects a 2D array, e.g.:
		 *
		 * 0: Array[4]
		 *        0: "borborson@uninett.no"   // OLD USERNAME
		 *        1: "bor.borson@uninett.no"  // OLD EMAIL
		 *        2: "borborson@feide.no"     // NEW USERNAME
		 *        3: "bor.borson@feide.no"    // NEW EMAIL
		 * 1: Array[4]
		 *      0: ...
		 *
		 * @param $postData
		 *
		 * @return array
		 */
		public function verifyAccountList($postData) {
			// Get/set POST values
			$userList = isset($postData['user_list']) ? $postData['user_list'] : false;
			// Check that all required data is here
			if(!$userList) {
				Response::error(400, 'Missing one or more required data fields from POST. Cannot continue without required data...');
			}
			// To be sent back to client...
			$responseObj = array();
			// Loop all user pairs in the CSV
			foreach($userList as $userCurrentAndNew) {
				// Must be two columns only for each entry
				if(sizeof($userCurrentAndNew) !== 4) {
					Response::error(400, 'Malformed CSV data structure. Cannot continue.');
				}
				// Check if old username has an account
				$currentLoginInfo = $this->_checkUserExists($userCurrentAndNew[0]);

				// If yes, we need to do more, otherwise skip to next user
				if($currentLoginInfo !== false) {
					// Check if the new username already has an account
					$newLoginInfo = $this->_checkUserExists($userCurrentAndNew[1]);
					// If yes, we have a situation (cannot move old to new, hence old account content will not be merged with new account)
					if($newLoginInfo !== false) {
						$responseObj['problem'][$userCurrentAndNew[0]]['message']              = 'Kan ikke migrere! Nytt brukernavn er allerede blitt tatt i bruk.';
						$responseObj['problem'][$userCurrentAndNew[0]]['account_info_current'] = $currentLoginInfo;
						$responseObj['problem'][$userCurrentAndNew[0]]['account_info_new']     = $newLoginInfo;
					} else {
						// Return old username and principal ID back to the client for final check before
						// it can make a MERGE request.
						$responseObj['ok'][$userCurrentAndNew[0]]['message']                      = 'Klar for fusjonering til nytt brukernavn!';
						$responseObj['ok'][$userCurrentAndNew[0]]['account_info_current']         = $currentLoginInfo;
						$responseObj['ok'][$userCurrentAndNew[0]]['account_info_new']['username'] = $userCurrentAndNew[2];
						$responseObj['ok'][$userCurrentAndNew[0]]['account_info_new']['email']    = $userCurrentAndNew[3];
					}
				} // Users with no existing account on the service
				else {
					$responseObj['ignore'][$userCurrentAndNew[0]]['message']                          = 'Hopper over siden ingen konto er registrert for dette brukernavnet.';
					$responseObj['ignore'][$userCurrentAndNew[0]]['account_info_current']['username'] = $userCurrentAndNew[0];
					$responseObj['ignore'][$userCurrentAndNew[0]]['account_info_current']['email']    = $userCurrentAndNew[1];
					$responseObj['ignore'][$userCurrentAndNew[0]]['account_info_new']['username']     = $userCurrentAndNew[2];
					$responseObj['ignore'][$userCurrentAndNew[0]]['account_info_new']['email']        = $userCurrentAndNew[3];
				}
			}

			// Done :-)
			return ($responseObj);
		}

		/**
		 * Check if a user exists. Returns false if not, otherwise user metadata.
		 *
		 * @param $username
		 *
		 * @return array|bool
		 */
		private function _checkUserExists($username) {
			$this->_logger('(BEFORE)', __LINE__, __FUNCTION__);

			// TODO: Check if it is faster to pull all users with new/old domain in a single query instead of looping 100s of usernames in individual calls...

			// Lookup account info for requested user
			$sqlUserInfoResponse = $this->relaySQL->query("
				SELECT userName, userEmail
				FROM tblUser
				WHERE userName = '$username'");
			// Note: relaySQL will handle any errors with the SQL call.
			$this->_logger('(AFTER)', __LINE__, __FUNCTION__);

			// Ok search, but user does not exist
			if(empty($sqlUserInfoResponse)) {
				return false;
			} else {
				// Safe to assume that only one row was returned, since username is unique.
				// Done :-)
				return array(
					'username' => $sqlUserInfoResponse[0]['userName'],
					'email'    => $sqlUserInfoResponse[0]['userEmail']
				);
			}
		}

		private function _logger($text, $line, $function) {
			if($this->DEBUG) {
				error_log($function . '(' . $line . '): ' . $text);
			}
		}


		/**
		 * WRITE TO TechSmith Relay DB
		 *
		 *
		 * @param $postData
		 *
		 * @return mixed
		 */
		public function migrateUserAccounts($postData) {
			// Get/set POST values
			$userList = isset($postData['user_list']) ? $postData['user_list'] : false;
			// Check that all required data is here
			if(!$userList) {
				Response::error(400, 'Missing one or more required data fields from POST. Cannot continue without required data...');
			}
			// To be sent back to client...
			$responseObj = array();
			// Loop all user pairs in the CSV
			foreach($userList as $index => $userObj) {
				// Check for required user info in the object
				if(!$userObj['current_username'] || !$userObj['current_email'] || !$userObj['new_username'] || !$userObj['new_email']) {
					Response::error(400, 'Malformed data structure. Cannot continue.');
				}
				// DO username change
				$usernameUpdateResponse = $this->_changeUsername($userObj['current_username'], $userObj['new_username'], $userObj['new_email']);

				// If yes, we need to do more, otherwise skip to next user
				if($usernameUpdateResponse !== false) {
					$responseObj['ok'][$userObj['current_username']] ['message']                      = 'Brukernavn fusjonert!';
					$responseObj['ok'][$userObj['current_username']] ['account_info_old']['username'] = $userObj['current_username'];
					$responseObj['ok'][$userObj['current_username']] ['account_info_old']['email']    = $userObj['current_email'];
					$responseObj['ok'][$userObj['current_username']] ['account_info_new']             = $usernameUpdateResponse;
				} else {
					$responseObj['problem'][$userObj['current_username']]['message']                       = 'Ukjent problem!';
					$responseObj['problem'][$userObj['current_username']] ['account_info_old']['username'] = $userObj['current_username'];
					$responseObj['problem'][$userObj['current_username']] ['account_info_old']['email']    = $userObj['current_email'];
					$responseObj['problem'][$userObj['current_username']] ['account_info_new']['username'] = $userObj['new_username'];
					$responseObj['problem'][$userObj['current_username']] ['account_info_new']['email']    = $userObj['new_email'];
				}
			}

			// Done :-)
			return ($responseObj);
		}


		/**
		 * WRITE OP
		 *
		 * Change username and email
		 *
		 * @param $oldUsername
		 * @param $newUsername
		 * @param $newEmail
		 *
		 * @return array
		 */
		private function _changeUsername($oldUsername, $newUsername, $newEmail) {

			$this->_logger('(BEFORE)', __LINE__, __FUNCTION__);
			//Run the update call requested principalId
			// $sqlChangeUsernameResponse = $this->relaySQL->query('SOME_QUERY_TO_CHANGE_USERNAME_AND_EMAIL');
			$this->_logger('(AFTER)', __LINE__, __FUNCTION__);
			// Exit on error
			if(SQL_QUERY_FAILED) {
				Response::error(400, 'User update failed: ' . $newUsername . ': ' . 'MESSAGE FROM SQL');
			}

			return array(
				'username' => $newUsername,
				'email'    => $newEmail
			);


			/* Dummy response - for testing
			return array(
				'principal_id' => $principalId,
				'username'     => $newUsername
			);
			*/

		}


		// ---------------------------- UTILS ----------------------------

		private function _responseToArray($response) {
			$newArr = Array();
			foreach($response as $child) {
				$newArr[] = $child;
			}

			return $newArr;
		}

		// ---------------------------- ./UTILS ----------------------------


	}



