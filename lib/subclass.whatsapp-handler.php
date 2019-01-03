<?php

namespace apt\thewhale;

class whatsapp_handler extends message_handler{	
	
	protected $funnel_connection;
	
	function set_connection(){
		$this->connection = new \PDO('mysql:host=localhost;dbname=duplixnet_zap_responder;charset=utf8', 'duplixnet_zap_responder', 'UOgliSqIc1rVFEqnzFkG90e2');
	}
	
	function get_specific_options(){
		$funnel = get_post($this->settings["funnel_id"]);
		
		if(!$funnel){
			echo json_encode(array(
								"status"=>"error",
								"message"=>"Funnel ID related to that Message isn't valid",
							));
			die();
		}
		
		$this->settings["field_owner_username"] = get_field("mauticfunnelint_maut_field_sponsor_username",$this->settings["funnel_id"]);
		$this->settings["field_owner_id"] = get_field("mauticfunnelint_maut_field_sponsor_id",$this->settings["funnel_id"]);
		$this->settings["field_whatsapp"] = get_field("mauticfunnelint_maut_field_whatsapp",$this->settings["funnel_id"]);
		$this->settings["whatsapp_prefix"] = get_field("mauticfunnelint_maut_whatsapp_prefix",$this->settings["funnel_id"]);
		$this->settings["field_last_msg"] = get_field("mauticfunnelint_maut_field_last_msg",$this->settings["funnel_id"]);
	}
	
	function send(){
		
		if($this->settings['type'] != "pendente"){
			echo json_encode(array(
								"status" => "error",
								"message" => "Message is not of the type 'pending'",
							));
			
			die();
		}
		
		$this->remove_pending();
		$this->save_to_send();
		
		switch($this->settings['behavior']){
			case "send_relative":
				//send time to Mautic
				$this->save_date_on_system();
				break;
			case "remove_previous":
			default:
		}
		
		//APP Integration
		//$url = "https://hooks.zapier.com/hooks/catch/3027492/e3gubd/";
		$url = "https://hook.integromat.com/9xlc416rfgno5n0taizpnte5r2btoi6x";
		
		$app_key = get_field("mautfunnel_appintegration_app_key","options");
		$client_key = get_field("mautfunnel_appintegration_app_client_key","options");
		
		if(empty($app_key) || empty($client_key)){
			echo json_encode(array(
							"status"=>"error",
							"message"=>"APP key or client not set.",
						));
			http_response_code(200);
			die();
		}
		
		$field = $this->settings['field_owner_id'];
		$user = get_user_by("ID",$this->recipient[$field]);
		
		$args = array(
			"app_key" => get_field("mautfunnel_appintegration_app_key","options"),
			"client_key" => get_field("mautfunnel_appintegration_app_client_key","options"),
			"user_email" => $user->data->user_email,
			"message" => "Nova Mensagem Pendente para ".$this->recipient['firstname'].". Acesse o APP e envie...",
		);
		
		$this->send_to_zapier($url,$args);
		
		
		echo json_encode(array(
							"status"=>"success",
							"message"=>"Request proccessed",
						));
		http_response_code(200);
		die();
	}
	
	function show_pending($owner_email){

		$owner = get_user_by("email",$owner_email);
		
		if($owner){
			$sql = "SELECT * FROM messages_pending WHERE owner = ".$owner->data->ID."";
			$query = $this->connection->query($sql);
			$result = $query->fetchAll();
			
			$results_final = array();
			if(count($result) > 0){
				foreach($result as $message){
					foreach($message as $key=>$value){
						if(!is_numeric($key)){
							$current_event[$key] = $value;
						}
					}
					$current_event["url_message"] = get_site_url(get_current_blog_id())."/whatsapp-automate-send/?message-id=".$message['id'];
					$results_final[] = $current_event;
				}
			}
			
			echo json_encode($results_final);
			die();
		}
		
		echo json_encode(array(
							"status"=>"error",
							"message"=>"User with email $owner_email didn't found on system",
						));
	}
	
	function show_not_sent($owner_email,$recipient){
		
		$owner = get_user_by("email",$owner_email);
		
		if($owner){
			$sql = "SELECT * FROM messages_not_sent WHERE recipient = '$recipient' AND owner = '".$owner->data->ID."'";
			$query = $this->connection->query($sql);
			$result = $query->fetchAll();
			
			$results_final = array();
			if(count($result) > 0){
				foreach($result as $message){
					
					foreach($message as $key=>$value){
						if(!is_numeric($key)){
							$current_event[$key] = $value;
						}
					}
					$current_event["url_message"] = get_site_url(get_current_blog_id())."/whatsapp-automate-send/?message-id=".$message['id']."&not_sent=1";
					$results_final[] = $current_event;
				}
			}
			
			echo json_encode($results_final);
			die();
		}
		
		echo json_encode(array(
							"status"=>"error",
							"message"=>"User with email $owner_email didn't found on system",
						));
	}

	function remove_pending(){
		
		//We will remove all the previous messages from the pending
		//table and will send it to the "not_sent".
		//Only the user will delete it from not_sent (use the APP for that)
		$sql = "SELECT * FROM messages_pending WHERE recipient = ".$this->settings["whatsapp_prefix"].$this->recipient[$this->settings["field_whatsapp"]]." AND owner = ".$this->recipient[$this->settings["field_owner_id"]]."";
		$query = $this->connection->query($sql);
		$result = $query->fetchAll();
		
		if($result[0]['message_id'] == $this->message_id){
			mail("admin@autopilottools.com","Erro Mensagens Repetidas","Message ID: ".$this->message_id);
			http_response_code(200);
			die();
		}
		
		if(count($result) < 1)
			return;
		
		foreach($result as $message){
			
			//insert in not_sent
			$sql = "INSERT INTO messages_not_sent (
						`message_id`,
						`message`,
						`message_html`,
						`recipient`,
						`owner`,
						`date_triggered`
					) VALUES (
						'{$message['message_id']}',
						'".str_replace("\\r\\n","\\\\r\\\\n",$message['message'])."',
						'{$message['message_html']}',
						'{$message['recipient']}',
						'{$message['owner']}',
						'{$message['date_triggered']}'
					)";
			$query = $this->connection->query($sql);
			
			//remove from pending
			$sql = "DELETE FROM messages_pending WHERE id='{$message['id']}'";
			$query = $this->connection->query($sql);
		}
	}
	
	function save_to_send(){
		
		$this->personalize_message();
		$message_content_linebreak = str_replace(array("<br />","<br>","<br/>"),"\\\\r\\\\n",$this->content);
		
		//insert in not_sent
		$sql = "INSERT INTO messages_pending (
					`message_id`,
					`message`,
					`message_html`,
					`recipient`,
					`owner`
				) VALUES (
					'{$this->message_id}',
					'$message_content_linebreak',
					'{$this->content}',
					'".$this->settings["whatsapp_prefix"].$this->recipient[$this->settings["field_whatsapp"]]."',
					'".$this->recipient[$this->settings["field_owner_id"]]."'
				)";
		$query = $this->connection->query($sql);
	}
	
	function save_date_on_system(){
		//connect on Funnel, update the field with current data.
		$this->funnel_connection = mail_server::factory($this->settings["funnel_id"]);
		
		$update_data[$this->settings['field_last_msg']] = date("Y-m-d H:i:s");
		
		$this->funnel_connection->update_lead($this->recipient['id'],$update_data,get_post($this->settings["funnel_id"]));
	}
	
	function set_recipient($recipient_data){
		
		if(!$recipient_data[$this->settings["field_whatsapp"]]){
			echo json_encode(array("status"=>"error","message"=>"Recipient don't have the WhatsApp Field"));
			die();
		}
		
		if(!$recipient_data[$this->settings["field_owner_id"]]){
			echo json_encode(array("status"=>"error","message"=>"Recipient don't have the User ID Field"));
			die();
		}
		
		$this->recipient = $recipient_data;
	}
}

?>