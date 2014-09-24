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
				return "- Cache Timeout";
			}else if($data->function == "optionsPageRequest"){
				return "- Delete Cache Button";
			}else if($data->function == "deleteCssAndJsCache"){
				return "- Delete Cache and Minified CSS/JS Button";
			}else if($data->function == "on_all_status_transitions"){
				$type = $data->args[2]->post_type;
				if($data->args[0] == "publish" && $data->args[1] == "publish"){
					return "<span>- The ".$type." has been updated</span><br><span>- #ID:".$data->args[2]->ID."</span><br><span>- One cached file has been removed</span>";
				}else if($data->args[0] == "publish" && $data->args[1] != "publish"){
					return "<span>- New ".$type." has been published</span><br><span>- ".$type." ID:".$data->args[2]->ID."</span>";
				}
				return "<span>- The ".$type." status has been changed.</span><br><span>- ".$data->args[1]." > ".$data->args[0]."</span><span> #ID:".$data->args[2]->ID."</span>";
			}else if($data->function == "wp_set_comment_status"){
					return "<span>- Comment has been marked as </span>"."<span>".$data->args[1]."</span><br><span>- Comment ID: ".$data->args[0]."</span><br><span>- One cached file has been removed</span>";
			}

			return $data->function;
		}

		public function printLogs(){
			?>
			<div id="delete-logs" style="display:none; padding-bottom:10px;">

				<h2 style="padding-left:20px;padding-bottom:10px;">Delete Cache Logs</h2>

				<table style="border:0;border-top:1px solid #DEDBD1;border-radius:0;margin-left: 20px;width: 95%;box-shadow:none;border-bottom: 1px solid #E5E5E5;" class="widefat fixed">
					<thead>
						<tr>
							<th style="border-left:1px solid #DEDBD1;border-top-left-radius:0;" scope="col">Date</th>
							<th style="border-right:1px solid #DEDBD1;border-top-right-radius:0;" scope="col">Via</th>
						</tr>
					</thead>
						<tbody>
							<?php if(count($this->getLogs()) > 0){ ?>
								<?php foreach ($this->getLogs() as $key => $log) { ?>
									<tr>
										<th style="vertical-align:top;border-left:1px solid #DEDBD1;" scope="row"><?php echo $log->date;?></th>
										<th style="border-right:1px solid #DEDBD1;"><?php echo isset($log->via) ? $this->decodeVia($log->via) : ""; ?></th>
									</tr>
								<?php } ?>
							<?php }else{ ?>
									<tr>
										<th style="border-left:1px solid #DEDBD1;" scope="row"><label>No Log</label></th>
										<th style="border-right:1px solid #DEDBD1;"></th>
									</tr>
							<?php } ?>
						</tbody>
				</table>
			</div>
			<?php if(get_bloginfo("language") == "tr-TR"){ ?>
				<script type="text/javascript">
					jQuery("#container-show-hide-logs").show();
				</script>
			<?php } ?>
			<script>
				jQuery("#show-delete-log, #hide-delete-log").click(function(e){
					if(e.target.id == "show-delete-log"){
						jQuery(e.target).hide();
						jQuery("#hide-delete-log").show();
						jQuery("#delete-logs").show();
						jQuery("div.tab2 form.delete-line").hide();
					}else if(e.target.id == "hide-delete-log"){
						jQuery(e.target).hide();
						jQuery("#delete-logs").hide();
						jQuery("#show-delete-log").show();
						jQuery("div.tab2 form.delete-line").show();
					}
				});
			</script>
			<?php
		}
	}
?>