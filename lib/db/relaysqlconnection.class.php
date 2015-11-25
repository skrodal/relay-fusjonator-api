<?php
	/**
	 * Handles DB Connection and queries
	 *
	 * @author Simon Skrodal
	 * @since  November 2015
	 */

	ini_set('mssql.charset', 'UTF-8');

	class RelaySQLConnection {

		private $connection, $config;

		function __construct($config) {
			// Get connection conf
			$this->config = $config;
		}

		/**
		 * @param $sql
		 *
		 * @return array
		 */
		public function query($sql) {
			//
			$this->connection = $this->getConnection();
			// Run query
			$query = mssql_query($sql, $this->connection);
			// On error
			if($query === false) {
				Response::error(500, $_SERVER["SERVER_PROTOCOL"] . ' DB query failed (SQL).');
			}
			// Response
			$response = array();
			//
			// Loop rows and add to response array
			if(mssql_num_rows($query) > 0) {
				while($row = mssql_fetch_assoc($query)) {
					$response[] = $row;
				}
			}
			// Free the query result
			mssql_free_result($query);
			// Close link
			$this->closeConnection();

			//
			return $response;
		}

		public function employeeProfileId() {
			return (int)$this->config['employeeProfileId'];
		}

		public function studentProfileId() {
			return (int)$this->config['studentProfileId'];
		}

		/**
		 *    Open MSSQL connection
		 */
		private function getConnection() {
			//
			$connection = mssql_connect($this->config['host'], $this->config['user'], $this->config['pass']);
			//
			if(!$connection) {
				Response::error(500, $_SERVER["SERVER_PROTOCOL"] . ' DB connection failed (SQL).');
			}
			//
			if(!mssql_select_db($this->config['db'])) {
				Response::error(500, $_SERVER["SERVER_PROTOCOL"] . ' DB table connection failed (SQL).');
			}
			return $connection;
		}

		/**
		 *    Close MSSQL connection
		 */
		private function closeConnection() {
			if($this->connection !== false) {
				mssql_close($this->connection);
			}
		}
	}