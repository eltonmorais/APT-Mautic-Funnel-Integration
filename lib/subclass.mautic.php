<?php

namespace apt\thewhale;

class mautic extends mail_server{	
	
	protected $parameters;
	protected $api_path;
	protected $lead_id;
	protected $lead;
	protected $request_type;
	protected $db_connection;
	
	protected function do_connection(){
		
		$ch = curl_init();
		
		switch($this->request_type){
			case "POST":
				$url = $this->configs['url']."/api/".$this->api_path;
				curl_setopt($ch, CURLOPT_POSTFIELDS, $this->parameters);
				break;
			case "PATCH":
				$url = $this->configs['url']."/api/".$this->api_path;
				curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
				curl_setopt($ch, CURLOPT_POSTFIELDS, $this->parameters);
				break;
			default:
				$url = $this->configs['url']."/api/".$this->api_path."?";
				$url .= http_build_query($this->parameters);
		}
		
		curl_setopt($ch, CURLOPT_URL,$url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
		curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
		curl_setopt($ch, CURLOPT_HTTPHEADER,
			array(
				"Authorization: Basic " . base64_encode($this->configs['username'].":".$this->configs['password'])
			));
		
		$result = curl_exec($ch);
		$status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$header = curl_getinfo($ch, CURLOPT_HTTPHEADER);
		$transfer = curl_getinfo($ch, CURLOPT_RETURNTRANSFER);
		
		curl_close($ch);
		
		return json_decode($result);
	}
	
	public function get_lead_by_id($id){}
	
	public function get_lead_all(){
		//set parameters
		$this->parameters = array(
			"search" => "",
			"start" => 0,
			"limit" => 1000000000,
		);
				
		$this->api_path = "contacts";
		$this->request_type = "GET";
		$result = $this->do_connection();
		
		if(count($result->contacts) > 0){
			return $result->contacts;
		}
		
		//Try with different configs
		$this->parameters = array();
		$result = $this->do_connection();
		
		if(count($result->contacts) > 0){
			return $result->contacts;
		}		
		
		return false;
	}
	
	public function get_lead($search){
		
		//set parameters
		$this->parameters = array(
			"search" => $search,
			"start" => 0,
			"limit" => 1,
		);
		
		$this->api_path = "contacts";
		
		$result = $this->do_connection();
		
		if(count($result->contacts) > 0){
			foreach($result->contacts as $contact){
				$this->lead = $contact;
				return $contact;
			}
		}
		
		return false;
	}
	
	function get_owner(){}
	
	function set_configs(){
		
		$this->configs['url'] = get_field("mauticfunnelint_intallation_url",$this->instance_id);
		$this->configs['username'] = get_field("mauticfunnelint_username",$this->instance_id);
		$this->configs['password'] = get_field("mauticfunnelint_password",$this->instance_id);
		$this->configs['display_name_field'] = get_field("aptoptpages_display_name_field","options");
		
		$this->configs['user_id_field'] = get_field("mauticfunnelint_wp_field_id",$this->instance_id);
		$this->configs['db_host'] = get_field("mauticfunnelint_mautic_db_host",$this->instance_id);
		$this->configs['db_user'] = get_field("mauticfunnelint_mautic_db_user",$this->instance_id);
		$this->configs['db_key'] = get_field("mauticfunnelint_mautic_db_key",$this->instance_id);
		$this->configs['db_name'] = get_field("mauticfunnelint_mautic_db_name",$this->instance_id);
		$this->configs['db_prefix'] = get_field("mauticfunnelint_mautic_db_prefix",$this->instance_id);
		
		if(!empty($this->configs['db_host'])
			&& !empty($this->configs['db_user'])
			&& !empty($this->configs['db_key'])
			&& !empty($this->configs['db_name'])
		){
			$this->db_connection = new \wpdb(
											$this->configs['db_user'],
											$this->configs['db_key'],
											$this->configs['db_name'],
											$this->configs['db_host']
										);
		}
	}
	
	function change_stage($new_stage,$lead_id = null){
		
		$stage = get_post($new_stage);
		
		if(is_null($lead_id))
			$lead_id = $this->lead_id;
		
		if($stage){
			$stage_mautic_id = get_field('mauticfunnelint_stage_id',$new_stage);
			
			//Check if it's different from current stage
			if($this->lead->stage->id != $stage_mautic_id){
								
				//connect
				$this->api_path = "stages/$stage_mautic_id/contact/$lead_id/add";
				$this->request_type = "POST";
				unset($this->parameters);
				$result = $this->do_connection();
				
				return $result;
			}
		}
		
		return false;
	}
	
	function do_add_tags($tags_array,$lead_id = null){
		
		if(is_null($lead_id))
			$lead_id = $this->lead_id;
		
		//connect
		$this->api_path = "contacts/$lead_id/edit";
		$this->parameters = array("tags" => $tags_array);
		$this->request_type = "PATCH";
		$result = $this->do_connection();
	}
	
	function create_user($user_data = array(),$post_data){
		
		$mautic_function_id = get_field('mauticfunnelint_mautic_role_id',$post_data->ID);
		
		$this->parameters = array(
								"created_by" => get_field("mauticfunnelint_admin_id","options"),
								"created_by_user" => get_field("mauticfunnelint_admin_id","options"),
								"online_status" => "offline",
								"signature" => "Atenciosamente, |FROM_NAME|",
								"preferences" => "a:0:{}",
								"email" => $user_data['email'],
								"first_name" => $user_data['firstName'],
								"last_name" => $user_data['lastName'],
								"username" => $user_data['username'],
								"password" => password_hash($user_data['password'], PASSWORD_BCRYPT),
								"role_id" => $mautic_function_id,
								"is_published" => 1,
								"date_added" => date("Y-m-d H:i:s"),
							);
		
		$this->db_connection->show_errors();
		$return = $this->db_connection->insert( $this->configs['db_prefix']."users", $this->parameters );
		$this->db_connection->print_error();
		
		//update_user_meta( $user_data['user_id'], $this->configs['user_id_field'], $this->db_connection->insert_id );
		update_field($this->configs['user_id_field'],$this->db_connection->insert_id,"user_".$user_data['user_id']);
	}
	
	function update_lead($lead_id,$update_array,$post_data){
		
		$this->db_connection->update('leads',$update_array,$lead);
		$this->db_connection->print_error();
	}
	
	function create_lead($lead_data,$post_data){
		
		$user = get_user_by("login",$lead_data['sponsor']);
		
		if(!$user){
			$user = get_user_by("email",$lead_data['sponsor']);
		}
		
		if(!$user){
			$user = get_user_by("login",$lead_data['sponsor']);
		}
		
		if($user){
			$user_id = "user_".$user->data->ID;
			$this->parameters['owner_id'] = get_field($this->configs['user_id_field'], $user_id);
		}
		
		$sql = "SELECT * FROM ".$this->configs['db_prefix']."leads WHERE email='{$lead_data['email']}'";		
		$this->db_connection->show_errors($sql);
		$result = $this->db_connection->get_results($sql);
		$this->db_connection->print_error();
		
		foreach($result as $key=>$row){
			if(isset($row->id)){
				return $row->id;
			}
		}
		
		$this->parameters['email'] = $lead_data['email'];
		$this->parameters['firstname'] = $lead_data['firstname'];
		$this->parameters['lastname'] = $lead_data['lastname'];
		$this->parameters['mobile'] = @$lead_data['ddd'].@$lead_data['mobile'];

		$this->parameters['is_published'] = "1";
		$this->parameters['preferred_profile_image'] = "gravatar";
		$this->parameters['internal'] = "a:0:{}";
		$this->parameters['social_cache'] = "a:0:{}";
		$this->parameters['date_identified'] = date("Y-m-d H:i:s");
		$this->parameters['date_added'] = date("Y-m-d H:i:s");
		
		$sponsor_email_field = get_field('mauticfunnelint_maut_field_sponsor_email',$post_data->ID);
		$sponsor_name_field = get_field('mauticfunnelint_maut_field_sponsor_name',$post_data->ID);
		$sponsor_username_field = get_field('mauticfunnelint_maut_field_sponsor_username',$post_data->ID);
		$sponsor_id_field = get_field('mauticfunnelint_maut_field_sponsor_id',$post_data->ID);
		
		$sponsor_name = get_field($this->configs["display_name_field"],"user_".$user->data->ID);
		if(!$sponsor_name){
			$sponsor_name = $user->data->display_name;
		}
		
		$this->parameters[$sponsor_email_field] = $user->data->user_email;
		$this->parameters[$sponsor_name_field] = $sponsor_name;
		$this->parameters[$sponsor_username_field] = $user->data->user_login;
		$this->parameters[$sponsor_id_field] = $user->data->ID;
		
		$this->db_connection->show_errors();
		$this->db_connection->insert( $this->configs['db_prefix']."leads", $this->parameters );
		$lastid = $this->db_connection->insert_id;
		
		if($lastid){
			return $lastid;
		}
		
		return false;
	}
}

?>