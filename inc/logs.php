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
			$arr = array();
			$via = debug_backtrace();

			if($via[3]["function"] == "on_all_status_transitions"){
				$arr["args"] = $via[3]["args"];
				$arr["function"] = $via[3]["function"];
			}else if($via[5]["function"] == "wp_set_comment_status"){
				$arr["args"] = $via[5]["args"];
				$arr["function"] = $via[5]["function"];
			}else{
				$arr["function"] = $via[3]["function"];
			}

			return $arr;
		}

		//to detect which function called deleteCache()
		public function decodeVia($data){
			if($data->function == "setSchedule"){
				return "Cache Timeout";
			}else if($data->function == "optionsPageRequest"){
				return "Delete Cache Button";
			}else if($data->function == "call_user_func_array"){
				return "New Post";
			}else if($data->function == "deleteCssAndJsCache"){
				return "Delete Cache and Minified CSS/JS Button";
			}else if($data->function == "on_all_status_transitions"){
				$type = $data->args[2]->post_type;
				if($data->args[0] == "publish" && $data->args[1] == "publish"){
					return "<span>- The ".$type." has been updated</span><br><span>- #ID:".$data->args[2]->ID."</span><br><span>- One cached file has been removed</span>";
				}else if($data->args[0] == "publish" && $data->args[1] != "publish"){
					return "<span>New ".$type." has been published</span><br><span> #ID:".$data->args[2]->ID."</span>";
				}
				return "<span>The ".$type." status has been changed.</span><br><span> ".$data->args[1]." > ".$data->args[0]."</span><span> #ID:".$data->args[2]->ID."</span>";
			}else if($data->function == "wp_set_comment_status"){
					return "<span>Comment has been marked as </span>"."<span>".$data->args[1]."</span><br><span> #Comment ID: ".$data->args[0]."</span><br><span>- One cached file has been removed</span>";
			}

			return $data->function;
		}
	}
?>