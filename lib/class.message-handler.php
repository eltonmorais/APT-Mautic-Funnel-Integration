<?php

namespace apt\thewhale;

abstract class message_handler{
	
	protected $content;
	public $settings;
	public $message_id;
	protected $connection;
	protected $recipient;
	
	function __construct(){
		$this->set_connection();
	}
	
	abstract function set_connection();
	abstract function set_recipient($recipient_data);
	abstract function get_specific_options();
	abstract function show_pending($owner);
	abstract function show_not_sent($owner,$recipient);
	
	function set_message($message_id){
		
		$this->message_id = $message_id;
		$message = get_post($message_id);
		
		if(@$message->post_type != "aptwhatsmsg"){
			echo json_encode(array(
								"status" => "error",
								"message" => "Message id not found",
							));
			
			die();
		}
		
		$this->get_options();
		
	}
	
	public function get_content(){
		
		if(!$this->message_id){
			
			echo json_encode(array(
								"status" => "error",
								"message" => "You need to set the Message first.",
							));
		}
						
		$this->personalize_message();
		
		$return['message'] = str_replace(array("<br />","<br>","<br/>"),"\\\\r\\\\n",$this->content);
		$return['message_html'] = str_replace(array("\r\n","\\r\\n","\\\\r\\\\n"),"<br />",$this->content);
		$return['message_url'] = urlencode($return['message']);
		
		return $return;
	}
	
	function get_options(){
		$this->settings["funnel_id"] = get_field("aptwhatsmsg_funnel_id",$this->message_id);
		$this->settings["type"] = get_field("aptwhatsmsg_type",$this->message_id);
		$this->settings["behavior"] = get_field("aptwhatsmsg_behavior",$this->message_id);
		$this->settings["use_if"] = get_field("aptwhatsmsg_use_if",$this->message_id);
		$this->settings["specific_stages"] = get_field("aptwhatsmsg_specific_stages",$this->message_id);
		$this->settings["stages"] = get_field("aptwhatsmsg_stages",$this->message_id);
		$this->settings["body"] = get_field("aptwhatsmsg_body",$this->message_id);
		
		$this->get_specific_options();
	}
	
	function personalize_message(){
		
		if(empty($this->settings["body"])){
			echo json_encode(array(
								"status" => "error",
								"message" => "Message have no content",
							));
			
			die();
		}
		
		if(!empty($this->recipient)){
			$this->content = $this->settings["body"];
			foreach($this->recipient as $key=>$value){
				$this->content = str_replace("%%".$key."%%",$value,$this->content);
			}
		}else{
			$this->content = $this->settings["body"];
		}
	}
	
	function send_to_zapier($url,$args){
		// Initialize curl
		$curl = curl_init();

		$jsonEncodedData = json_encode($args);
		$opts = array(
			CURLOPT_URL             => $url,
			CURLOPT_RETURNTRANSFER  => true,
			CURLOPT_CUSTOMREQUEST   => 'POST',
			CURLOPT_POST            => 1,
			CURLOPT_POSTFIELDS      => $jsonEncodedData,
			CURLOPT_HTTPHEADER  => array('Content-Type: application/json','Content-Length: ' . strlen($jsonEncodedData))                                                                       
		);

		// Set curl options
		curl_setopt_array($curl, $opts);

		// Get the results
		$result = curl_exec($curl);

		// Close resource
		curl_close($curl);
	}
	
	abstract function send();
}

?>