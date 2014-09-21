<?php
	class WpFastestCacheLogs{
		private $name = "";
		private $type = "";
		private $limit = 0;
		private $logs = array();

		public function __construct($type){
			$this->type = $type;

			if($this->type == "delete"){
				$this->name = "WpFcDeleteCacheLogs";
				$this->limit = 25;
			}

			$this->setLogs();
		}

		public function action(){
			if($this->type == "delete"){
				$log = new stdClass();
				$log->date = date("d-m-Y @ H:i:h");
				$log->via = $this->getVia();
			}

			if(!in_array($log, $this->logs)){
				array_unshift($this->logs, $log);

				if($this->limit < count($this->logs)){
					array_pop($this->logs);
				}

				$this->updateDB();
			}
		}

		public function updateDB(){
			if(get_option($this->name)){
				update_option($this->name, json_encode($this->logs));
			}else{
				add_option($this->name, json_encode($this->logs), null, "no");
			}
		}

		public function setLogs(){
			if($json = get_option($this->name)){
				$this->logs = (array)json_decode($json);
			}
		}

		public function getLogs(){

			return $this->logs;
		}

		public function getVia(){
			$via = debug_backtrace();

			if($via[3]["function"] == "do_action" || $via[3]["function"] == "call_user_func_array"){
				//mail("bizimplanet.com@gmail.com", "rrr", json_encode($via));
			}
			return $via[3]["function"];
		}

		//to detect which function called deleteCache()
		public function decodeVia($data){
			if($data == "setSchedule"){
				return "Cache Timeout";
			}else if($data == "optionsPageRequest"){
				return "Delete Cache Button";
			}else if($data == "call_user_func_array"){
				return "New Post";
			}else if($data == "deleteCssAndJsCache"){
				return "Delete Cache and Minified CSS/JS Button";
			}
			return $data;
		}
	}
?>