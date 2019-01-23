<?php

namespace apt\thewhale;

/**
 * To extend the_whale_framework don't forget the /config.php file on the
 * plugin root folder
 */
class mautic_funnel_integration extends the_whale_framework {
	
	protected $mfi_id;
	protected $owner;
	protected $lead;
	protected $connection;
	protected $mail_server_id;
	protected $current_post;
	protected $get_keys = array(
					"aptFunnelID",
					"aptFunnelStageID",
					"aptFunnelOwner",
					"aptFunnelLead",
					"aptFunnelNewStageID",
				);
	
	function plugin(){
		
		add_action( 'init',array($this,'create_cpost'),1000);
		add_action( 'init', array($this,'create_ctaxonomy'),1000);
		add_filter( 'term_link', array($this,'term_to_type'), 10, 3 );
		add_action('apt_cpagto_subscription_done',array($this,'create_emailserver_user'),10,2);
		
		if(!is_admin() && !$this->is_bot()){
			
			if(strpos($_SERVER["REQUEST_URI"], "aptFunnelAction=") !== false){
				if($_GET['aptFunnelAction'] == 'lead_form'){
					
					if(empty($_POST)){
						$body = json_decode(file_get_contents('php://input'));
						
						foreach($body as $field=>$value){
							$_POST[$field] = $value;
						}
					}
					
					add_filter('init', array($this,'proccess_lead_form'));
				}else{
					add_filter('wp_loaded', array($this,'proccess_request'));
				}
			}else{
				add_filter('wp', array($this,'proccess_visit'));
			}
		}
	}
	
	function is_bot(){
		// User lowercase string for comparison.
		$user_agent = strtolower($_SERVER['HTTP_USER_AGENT']);
		
		// A list of some common words used only for bots and crawlers.
		$bot_identifiers = array(
			'bot',
			'slurp',
			'crawler',
			'spider',
			'curl',
			'facebook',
			'fetch',
			'whatsapp',
		);
		
		// See if one of the identifiers is in the UA string.
		foreach ($bot_identifiers as $identifier) {
			if (strpos($user_agent, $identifier) !== FALSE) {
				return TRUE;
			}
		}
		return FALSE;
	}
	
	function check_cookies(){
		foreach($this->get_keys as $key){
			if(empty($_GET[$key])){
				//get from cookie
				$_GET[$key] = @$_COOKIE[$key];
			}else{
				//save on cookie
				setcookie($key,$_GET[$key],time()+2592000,COOKIEPATH,COOKIE_DOMAIN);			
			}
		}
	}
	
	function proccess_generic(){
		$this->check_cookies();
		$this->set_connection();
	}
	
	function set_connection(){
		
		if(!empty($_GET['aptFunnelID'])){
			$this->connection = mail_server::factory($_GET['aptFunnelID']);
		}else{
			if(!empty($_GET['aptFunnelStageID'])){
				$aptFunnelID = get_field('mauticfunnelint_email_system_rel',$_GET['aptFunnelStageID']);
				$this->connection = mail_server::factory($aptFunnelID);
			}
		}
	}
	
	function proccess_lead_form(){
		
		if(empty($_POST['sponsor'])){
			
			if(!empty($_POST['aptlinkcomp'])){
				
				$options['distribution_type'] = get_field('aptlinkcom_distribution_type',$_POST['aptlinkcomp']);
				$options['users_selection'] = get_field('aptlinkcom_users_selection',$_POST['aptlinkcomp']);
				$options['selection_membership'] = get_field('aptlinkcom_selection_membership',$_POST['aptlinkcomp']);
				$options['users'] = get_field('aptlinkcom_users',$_POST['aptlinkcomp']);
				$options['page'] = get_field('aptlinkcom_page',$_POST['aptlinkcomp']);
				$options['post_id'] = $_POST['aptlinkcomp'];
				
				$class_to_call = "apt\\thewhale\\link_comp_".$options['users_selection'];
				
				$link_comp = new $class_to_call($options);
				
				$user = $link_comp->select_user();
				
				if($user){
					$_POST['sponsor'] = $user->data->user_login;
				}
			}
		}
		
		if(empty($_POST['sponsor'])){
			wp_die("Nenhum patrocinador. Contacte o administrador do sistema.");
		}
		
		if(empty($_POST['server'])){
			wp_die("Serviço de email não identificado.");
		}
		
		if(empty($_POST['email'])){
			wp_die("Email não preenchido");
		}
		
		$email_server = get_post($_POST['server']);
		
		if(is_wp_error($email_server)){
			wp_die("Serviço de Email selecionado não existe.");
		}
		
		$connection = mail_server::factory($email_server->ID);		
		
		$add_lead = $connection->create_lead($_POST,$email_server);
		
		if($add_lead !== false){
			
			do_action("apt_funnel_add_lead",$_POST);
		
			if(!empty($_POST['redirect_to'])){
				if($_POST['redirect_to'] != "http_response"){
					wp_redirect($_POST['redirect_to']);
					exit;
				}else{
					http_response_code(200);
					die();
				}
			}else{
				wp_die("Cadastro realizado.");
			}
		}
		wp_die("Um erro ocorreu.");
	}
	
	static function get_owner_id($lead,$server){		
		
		$connection = mail_server::factory($server);
		
		if(!is_object($lead)){
			$lead = $connection->get_lead($lead);
		}
		
		if($lead === false){
			return false;
		}else{
			$field_owner_id = get_field("mauticfunnelint_maut_field_sponsor_id",$server);
			return @$lead->fields->all->$field_owner_id;
		}
	}
	
	function proccess_request(){
		
		$this->proccess_generic();
				
		switch($_GET['aptFunnelAction']){
			//case "tryLogin":
				//echo json_encode(array("status"=>"success"));
				//http_response_code(412);
				//break;
			case "getFunnels":
				$funnels = array();
				$query = new \WP_Query(array(
					'post_type' => 'aptemailserver',
					'post_status' => 'publish',
					'posts_per_page' => -1,
				));
				
				if ( $query->have_posts() ) {
					
					if(!empty($_GET['aptFunnelOwner'])){
						$user = get_user_by("email",$_GET['aptFunnelOwner']);
						
						if($user){
							$check_funnel_field = true;
						}
					}
					
					while ( $query->have_posts() ) {
						
						$query->the_post();
						
						if($check_funnel_field){
							$id_field = get_field("mauticfunnelint_wp_field_id",get_the_ID());
							
							$field_content = get_field($id_field,"user_".$user->data->ID);
							
							if($field_content === ""){
								$field_content = get_user_meta($user->data->ID,$id_field,true);
							}
							
							
							if($field_content === ""){
								continue;
							}
						}
						
						
						$funnel['name'] = get_the_title();
						$funnel['id'] = get_the_ID();
						$funnels[] = $funnel;
						
					}
					/* Restore original Post Data */
					wp_reset_postdata();
				}
				
				echo json_encode($funnels);
				break;
			case "getLeads":
				
				$this->mail_server_id = $_GET['aptFunnelID'];
				
				$contacts = null;
				$count = 0;

				while(empty($contacts) && $count < 10){
					$contacts = $this->connection->get_lead_all();
					$count++;
				}

				if(empty($contacts)){
					echo json_encode(array());
					die();
				}
				
				foreach($contacts as $contact){

					unset($lead);

					foreach($contact as $k=>$v){
						$lead[$k] = $v;
					}
					
					$lead["capital_firstname"] = ucfirst($contact->firstname);
					$lead["capital_lastname"] = ucfirst($contact->lastname);
					$lead["last_activity"] = $contact->last_active;
					$lead["subscription_date"] = $contact->date_identified;
					$lead["current_stage_name"] = $contact->name;
					$lead["current_stage_description"] = $contact->description;
					$lead["current_stage_id"] = $contact->stage_id;
					$lead["funnel_id"] = $_GET['aptFunnelID'];
					$lead["avatar"] = "https://www.gravatar.com/avatar/".md5( strtolower( trim( $contact->email )))."?s=200";

					$all_leads[] = $lead;
				}
				
				if(isset($all_leads))
					echo json_encode($all_leads);
								
				break;
			case "getStageMessages":
				$this->lead = $this->connection->get_lead($_GET['aptFunnelLead']);
				$this->current_post = get_post($_GET['aptFunnelStageID']);
				$this->show_messages();
				die();
				break;
			
			case "getAvailableStages":
			
				$this->lead = $this->connection->get_lead($_GET['aptFunnelLead']);
				
				//currentFunnel
				$available = array();
				
				$this->current_post = get_post($_GET['aptFunnelStageID']);
				$available_stages = get_field('mauticfunnelint_available_stage',$this->current_post->ID);
				
				foreach($available_stages as $stage){
					$post = get_post($stage);
					$new_stage['stage_id'] = $post->ID;
					$new_stage['name'] = $post->post_title;
					$new_stage['description'] = $post->post_content;
					$new_stage['email'] = $_GET['aptFunnelLead'];
					$new_stage['funnel_id'] = $_GET['aptFunnelID'];
					$available[] = $new_stage;
				}
				
				echo json_encode($available);
				
				break;
			
			case "checkFieldsFill":
			
				$user = get_user_by("email",$_GET['aptFunnelOwner']);
				
				if(!$user){
					http_response_code(412);
					break;
				}
				
				$fields = str_getcsv($_GET['aptFieldsToCheck']);
				
				if(count($fields) < 1){
					http_response_code(412);
					break;
				}
				
				foreach($fields as $field){
					if(empty(get_field($field,"user_".$user->data->ID))){
						http_response_code(412);
						break;
					}
				}
				
				echo json_encode(
					array(
						"status" => "success",
						"message" => "Fields filled.",
					)
				);
				http_response_code(200);
				break;
				
				break;
			
			case "changeUserData":
				
				$user = get_user_by("email",$_GET['aptFunnelOwner']);
				
				if(!$user){
					//echo json_encode(array("status" => "not_exist"));
					//http_response_code(412);
					die();
				}				
				
				$acf_field_groups = acf_get_field_groups();
			
				if(count($acf_field_groups) <  1){
					echo json_encode(
						array(
							"status" => "error",
							"message" => "There's no Custom User Fields.",
						)
					);
					die();
				}
				
				//I need all the custom Options Pages
				$posts = get_posts(array(
					'post_type' => 'acf-options-page',
				));
				
				foreach($posts as $post){
					if(get_post_meta($post->ID,'_acfop_save_to',true) == 'current_user'){
						$all_created_pages[] = get_post_meta($post->ID,'_acfop_slug',true);
					}
				}
				
				if(count($all_created_pages) < 1){
					echo json_encode(
						array(
							"status" => "error",
							"message" => "There's no Custom User Pages.",
						)
					);
					break;
				}
				
				foreach($acf_field_groups as $group){
					if(in_array($group['location'][0][0]['value'],$all_created_pages)){
						$option_groups[] = $group;
					}
				}
					
				foreach($option_groups as $group){
					$gfields = acf_get_fields($group['key']);
					foreach($gfields as $gfield){
						$custom_fields[$gfield['name']] = $gfield['key'];
					}
				}
				
				foreach($_GET as $field=>$value){
					
					if(isset($custom_fields[$field])){
						update_field($custom_fields[$field],$value,"user_".$user->data->ID);
						//update_user_meta($user->data->ID,$field_acf['key'],$value);
						$saved = true;
					}else{
						
						$field_object = get_field_object($field);
						
						if($field_object){
							update_field($field,$value,"user_".$user->data->ID);
							$saved = true;
						}
					}
				}
				
				if($saved){
					echo json_encode(
						array(
							"status" => "success",
							"message" => "Dado atualizados com sucesso.",
						)
					);
				}else{
					echo json_encode(
						array(
							"status" => "error",
							"message" => "Campo não encontrado.",
						)
					);
				}
				
				break;
			
			case "getUserData":
				$user = get_user_by("email",$_GET['aptFunnelOwner']);
				
				if(!$user){
					echo json_encode(array("status" => "not_exist"));
					http_response_code(200);
					die();
				}
				
				$custom_fields = get_fields("user_".$user->data->ID);
				if(!is_array($custom_fields)){
					$custom_fields = array();
				}
				
				$user_fields = $this->get_user_fields();
				if(!is_array($user_fields)){
					$user_fields_data = array();
				}else{
					foreach($user_fields as $field){
						$user_fields_data[$field] = get_field($field,"user_".$user->data->ID);
					}
				}
				
				if(!is_array($custom_fields)){
					$custom_fields = array();
				}
				
				$user_data = array_merge($custom_fields,get_object_vars($user->data),$user_fields_data);
								
				if(class_exists("\apt\\thewhale\\paginas_personalizadas") && !empty($_GET['aptMembership'])){
					if(get_field("aptpersonpages_limit_users","options") == "membership"){
						if(class_exists("\apt_dynamic_memberships")){
							$member = new \apt_dynamic_memberships($user);
							
							if (!$member->has_membership($_GET['aptMembership'])) {
								$user_data["status"] = "inactive";
								echo json_encode($user_data);
								http_response_code(200);
								die();
							}
						}
					}
				}
				
				$user_data["status"] = "active";
				echo json_encode($user_data);
					http_response_code(200);
					die();
				
				
				break;
			
			case "getUserStatus":
				$user = get_user_by("email",$_GET['aptFunnelOwner']);
				
				if(!$user){
					echo json_encode(array("status" => "not_exist"));
					http_response_code(200);
					die();
				}
				
				$custom_fields = get_fields("user_".$user->data->ID);
				if(!is_array($custom_fields)){
					$custom_fields = array();
				}
				
				$user_fields = $this->get_user_fields();
				if(!is_array($user_fields)){
					$user_fields_data = array();
				}else{
					foreach($user_fields as $field){
						$user_fields_data[$field] = get_field($field,"user_".$user->data->ID);
					}
				}
				
				if(!is_array($custom_fields)){
					$custom_fields = array();
				}
				
				$user_data = array_merge($custom_fields,get_object_vars($user->data),$user_fields_data);
								
				if(class_exists("\apt\\thewhale\\paginas_personalizadas") && !empty($_GET['aptMembership'])){
					if(get_field("aptpersonpages_limit_users","options") == "membership"){
						if(class_exists("\apt_dynamic_memberships")){
							$member = new \apt_dynamic_memberships($user);
							
							if (!$member->has_membership($_GET['aptMembership'])) {
								$user_data["status"] = "inactive";
								echo json_encode($user_data);
								http_response_code(200);
								die();
							}
						}
					}
				}
				
				$user_data["status"] = "active";
				echo json_encode($user_data);
					http_response_code(200);
					die();
				
				
				break;
			
			case "checkUser":
				
				if(empty($_GET['aptFunnelOwner']) || $_GET['aptFunnelOwner'] == "Onbekend"){
					//echo json_encode(array("status" => "error","message" => "Didn't received username/email"));
					http_response_code(412);
					die();
				}
				
				$user = get_user_by("email",$_GET['aptFunnelOwner']);
				
				if(!$user){
					//echo json_encode(array("status" => "error","message" => "User not found"));
					http_response_code(412);
					die();
				}
				
				//if
				//if class membership exists
				//check membership
				//if don't have access, block
				if(class_exists("\apt\\thewhale\\paginas_personalizadas") && !empty($_GET['aptMembership'])){
					
					if(get_field("aptpersonpages_limit_users","options") == "membership"){
						
						if(class_exists("\apt_dynamic_memberships")){
							
							$member = new \apt_dynamic_memberships($user);
							
							if (!$member->has_membership($_GET['aptMembership'])) {
								
								//json_encode(array("status" => "error","message" => "User don't have Membership"));
								http_response_code(412);
								die();
							}
						}
					}
				}
				
				$custom_fields = get_fields("user_".$user->data->ID);
				if(!is_array($custom_fields)){
					$custom_fields = array();
				}
				
				$user_fields = $this->get_user_fields();
				if(!is_array($user_fields)){
					$user_fields_data = array();
				}else{
					foreach($user_fields as $field){
						$user_fields_data[$field] = get_user_meta($user->data->ID,$field,true);
						if(empty($user_fields_data[$field])){
							$user_fields_data[$field] = get_field($field,"user_".$user->data->ID);
						}
					}
				}
				
				if(!is_array($custom_fields)){
					$custom_fields = array();
				}
				
				$user_data = array_merge($custom_fields,get_object_vars($user->data),$user_fields_data);
				
				$user_data["status"] = "active";
				echo json_encode($user_data);
				http_response_code(200);
				die();
				
				break;
			
			case "createNewLead":
				
				break;
			
			case "triggMessage":
				
				$this->lead = $this->connection->get_lead($_GET['aptFunnelLead']);
				$owner_id = $this->get_owner_id($this->lead,$_GET['aptFunnelID']);
				
				$this->owner = get_user_by("ID",$owner_id);
				
				if($this->lead){
					//Message type | whatsapp - telegram - sms - etc - push
					//For now we will hard code whatsapp, and when we
					//decide to work with other platforms, we will choose
					//it here
					$platform = "apt\\thewhale\\whatsapp_handler";
					
					//call the proper class
					$courier = new $platform();
					
					if($_GET['aptMessageID']){
						
						//set the message configs. Send the MessageID (Message's Post ID)
						$courier->set_message($_GET['aptMessageID']);
						
						//Personalize it. Parameter is an array with the fields
						//(using json_encode/decode to transform object in array
						//$courier->personalize_message();
						$courier->set_recipient(json_decode(json_encode($this->lead->fields->all),true));
						//Send it. With WhatsApp, it will save it on the Pending Messages DB
						$courier->send();
					}else{
						echo json_encode(array(
							"status" => "error",
							"message" => "Message ID not received.",
						));
					}
					
					break;
				}else{
					http_response_code(412);
					die();
				}
				break;
			
			case "aptMessage":
				
				$method = strtolower(@$_GET['aptMethod']);
				
				switch($method){
					
					case "show_templates":
						
						$this->lead = $this->connection->get_lead($_GET['aptFunnelLead']);
						$owner_id = $this->get_owner_id($this->lead,$_GET['aptFunnelID']);
						
						$this->owner = get_user_by("ID",$owner_id);
					
						$posts = get_posts(array(
							'post_type' => 'aptwhatsmsg',
							'numberposts' => -1,
						));
						
						$available = array();
						
						foreach($posts as $message){
							
							if(get_field("aptwhatsmsg_type",$message->ID) == "pendente"){
								continue;
							}
							
							if(get_field("aptwhatsmsg_specific_stages",$message->ID)){
								if(!in_array($_GET['aptFunnelStageID'],get_field("aptwhatsmsg_stages",$message->ID))){
									continue;
								}
							}
							
							if(get_field("aptwhatsmsg_funnel_id",$message->ID) != $_GET['aptFunnelID']){
								continue;
							}
							
							$platform = "apt\\thewhale\\whatsapp_handler";
							$courier = new $platform();
							
							$courier->set_message($message->ID);
							$courier->set_recipient(json_decode(json_encode($this->lead->fields->all),true));
							
							$current_message = array_merge($courier->settings,$courier->get_content());
							$current_message['mobile'] = $this->lead->fields->all->mobile;
						
							$available[] = $current_message;
						}
						
						echo json_encode($available);
						
						break;
					
					case "show_pending":
						$platform = "apt\\thewhale\\whatsapp_handler";
						$courier = new $platform();
						
						$courier->show_pending($_GET['aptFunnelOwner']);
						break;
						
					case "show_notsent":
						$platform = "apt\\thewhale\\whatsapp_handler";
						$courier = new $platform();
						
						$courier->show_not_sent($_GET['aptFunnelOwner'],$_GET['aptRecipient']);
						break;
						
					case "trigg":
					
						$this->lead = $this->connection->get_lead($_GET['aptFunnelLead']);
						$owner_id = $this->get_owner_id($this->lead,$_GET['aptFunnelID']);
						
						$this->owner = get_user_by("ID",$owner_id);
						
						if($this->lead){
							
							//Message type | whatsapp - telegram - sms - etc - push
							//For now we will hard code whatsapp, and when we
							//decide to work with other platforms, we will choose
							//it here
							$platform = "apt\\thewhale\\whatsapp_handler";
							
							//call the proper class
							$courier = new $platform();
							
							if($_GET['aptMessageID']){
								
								
								//set the message configs. Send the MessageID (Message's Post ID)
								$courier->set_message($_GET['aptMessageID']);
								
								//Personalize it. Parameter is an array with the fields
								//(using json_encode/decode to transform object in array
								//$courier->personalize_message();
								$courier->set_recipient(json_decode(json_encode($this->lead->fields->all),true));
								
								//Send it. With WhatsApp, it will save it on the Pending Messages DB
								$courier->send();
							}
							
							break;
						}else{
							http_response_code(412);
							die();
						}
					}
					
					break;
			
				case "changeStage":
					if(empty($this->lead)){
						$this->lead = $this->connection->get_lead($_GET['aptFunnelLead']);
					}
					
					$result = $this->connection->change_stage($_GET['aptFunnelNewStageID'],$this->lead->id);
					$json = array();
					if($result->success == 1){
						$json['result'] = 1;
						http_response_code(200);
					}else{
						$json['result'] = 0;
						http_response_code(400);
					}
					
					echo json_encode($json);
					break;
		}
				
		die();
	}
	
	function create_emailserver_user($user,$product){
		
		$integrate_all = false;
		
		$plataforma = get_field('aptcpagto_plataforma',$product->ID);
		$memberships = get_field('aptcpagto_memberships',$product->ID);
		$integrations = get_field('aptcpagto_integrations',$product->ID);
		
		foreach($integrations as $integration){
			if($integration['integrate_with'] != 'apt_funnel'){
				continue;
			}
			
			if($integration['apt_funnel_behavior'] == 'all'){
				$integrate_all = true;
			}else{
				foreach($integration['aptfunnel_id'] as $funnel_id){
					$funnel_to_integrate[] = $funnel_id;
				}
			}
			
			break;
		}
		
		$posts = get_posts(array(
			'post_type' => 'aptemailserver',
		));
		
		$user_data['user_id'] = $user->data->ID;
		$user_data['username'] = $user->data->user_email;
		$user_data['email'] = $user->data->user_email;
		if(!empty($user->data->user_firstname)){
			$user_data['firstName'] = $user->data->user_firstname;
		}else{
			$user_data['firstName'] = "Seu";
		}
		
		if(!empty($user->data->user_lastname)){
			$user_data['lastName'] = $user->data->user_lastname;
		}else{
			$user_data['lastName'] = "Patrocinador";
		}
		
		foreach($posts as $post){
			
			if(!get_field('mauticfunnelint_create_new_user',$post->ID)){
				continue;
			}
			
			if(!$integrate_all){
				if(!in_array($post->ID,$funnel_to_integrate)){
					continue;
				}
			}
			
			$user_data['password'] = get_field('mauticfunnelint_user_password',$post->ID);
			
			$connection[$post->ID] = mail_server::factory($post->ID);
			$created_user = $connection[$post->ID]->create_user($user_data,$post);
			
		}
	}
	
	function proccess_visit(){
		
		$this->proccess_generic();
		
		$current_post = get_post();		
		
		if(!$this->connection){
			$this->connection = mail_server::factory($_GET['aptFunnelID']);
		}
		
		if($this->connection && !empty($_GET['aptFunnelLead'])){
			$this->lead = $this->connection->get_lead($_GET['aptFunnelLead']);
		}else{
			$this->lead = false;
		}
		
		$this->do_action();
		
	}
	
	function set_active_owner(){
		if(!class_exists("\WP_Session")){
			wp_die("Activate WP Session Manager.");
		}
		
		$wp_session = \WP_Session::get_instance();
		
		$this->owner = $wp_session['pagperson_active_user'];
	}
		
	function do_action(){
		
		//block page
		$block_option = get_field('mauticfunnelint_block_mode');
		$block_redirect_to = get_field('mauticfunnelint_redirect_to');
		$block_message = get_field('mauticfunnelint_message_block');
		$tags_to_add = get_field('mauticfunnelint_add_tags');
		$stage_new = get_field('mauticfunnelint_new_stage');
		
		if($this->lead === false){
			switch($block_option){
				case "redirect":
					wp_redirect($block_redirect_to);
					exit;
					break;
					
				case "message":
					wp_die($block_message);
					break;
			}
		}else{
			//add tag
			/*
			if(!empty($tags_to_add)){
				$tags_array = str_getcsv($tags_to_add);
				$this->connection->add_tags($tags_array);
			}
			*/
			
			//if there's a new stage to be added...
			if(!empty($stage_new)){
				
				$change_stage = false;
				
				//If the lead has a stage, then I will check the ID and see if
				//it's allowed to change for the specific stage. If there's no
				//stage I will allow and create a new one.
				if(isset($this->lead->stage->id)){
					
					$current_stage = get_page_by_title( $this->lead->stage->name,OBJECT,"aptfunnelstage" );
					$current_email_server = $this->connection->get_id();
					
					$posts = get_posts(array(
						'numberposts'	=> -1,
						'post_type'		=> 'aptfunnelstage',
						'meta_query'	=> array(
							'relation'		=> 'AND',
							array(
								'key'	 	=> 'mauticfunnelint_stage_id',
								'value'	  	=> $this->lead->stage->id,
								'compare' 	=> '=',
							),
							array(
								'key'	  	=> 'mauticfunnelint_email_system_rel',
								'value'	  	=> $current_email_server,
								'compare' 	=> '=',
							),
						),
					));
					
					if($posts){
						
						$current_stage = $posts[0];
						
						//echo "<!-- current_stage: {$current_stage} -->";
						
						$available_automatic_stages = get_field("mauticfunnelint_available_stage_automation",$current_stage->ID);
						
						foreach($available_automatic_stages as $stage_id){
							//echo "<!-- stage_id: {$stage_id} | stage_new: {$stage_new} -->";
							if($stage_id == $stage_new){
								//echo "<!-- change_stage = true -->";
								$change_stage = true;
								break;
							}
						}
					}else{
						//echo "<!-- change_stage = true -->";
						$change_stage = true;
					}
				}else{
					//echo "<!-- change_stage = true -->";
					$change_stage = true;
				}
				
				//Only if change_stage is true the stage will be added
				if($change_stage){
					$this->connection->change_stage($stage_new,$this->lead->id);
				}
			}
		}
	}
	
	function show_messages(){
		
		if($this->lead === false){
			wp_die("Para mostrar as mensagens personalizadas é preciso identificar o Lead.");
		}
		
		$posts = get_posts(array(
			'post_type' => 'aptwhatsmsg',
			'numberposts' => -1,
		));
		
		$available = array();
		
		foreach($posts as $message){
			
			if(get_field("aptwhatsmsg_type",$message->ID) == "pendente"){
				continue;
			}
			
			if(get_field("aptwhatsmsg_specific_stages",$message->ID)){
				if(!in_array($_GET['aptFunnelStageID'],get_field("aptwhatsmsg_stages",$message->ID))){
					continue;
				}
			}else{
				if(!in_array($_GET['aptFunnelID'],get_field("aptwhatsmsg_funnels",$message->ID))){
					continue;
				}
			}
			
			$platform = "apt\\thewhale\\whatsapp_handler";
			$courier = new $platform();
			
			$courier->set_message($message->ID);
			$courier->set_recipient(json_decode(json_encode($this->lead->fields->all),true));
			
			$current_message = array_merge($courier->settings,$courier->get_content());
			$current_message['mobile'] = $this->lead->fields->all->mobile;
		
			$available[] = $current_message;
		}
		
		echo json_encode($available);
		die();
		
	}
	
	function message_settings(){
		
		$this->message_fields = array(
			"nome" => $this->lead->fields->core->firstname->value,
			"email" => $this->lead->fields->core->email->value,
			//"patrocinador-whats" => "",
		);
	}
	
	function message_personalization($original_message){
		
		$personalized_message = $original_message;
		
		foreach($this->message_fields as $field=>$value){
			$personalized_message = str_replace("%%".$field."%%",$value,$personalized_message);
		}
		
		return $personalized_message;
	}
	
	function term_to_type($link, $term, $taxonomy){
		
		$custom_taxonomys = array('apt_funnel','apt_stage');
		$custom_taxonomys_cpost['apt_funnel'] = 'aptemailserver';
		$custom_taxonomys_cpost['apt_stage'] = 'aptfunnelstage';
		
		if ( in_array($taxonomy,$custom_taxonomys) ) {
			$post_id = $this->get_post_id_by_slug( $term->slug, $custom_taxonomys_cpost[$taxonomy] );

			if ( !empty( $post_id ) )
				return get_permalink( $post_id );
		}

		return $link;
	}
	
	function get_post_id_by_slug( $slug, $post_type ) {
		global $wpdb;

		$slug = rawurlencode( urldecode( $slug ) );
		$slug = sanitize_title( basename( $slug ) );

		$post_id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_name = %s AND post_type = %s", $slug, $post_type ) );

		if ( is_array( $post_id ) )
			return $post_id[0];
		elseif ( !empty( $post_id ) );
			return $post_id;

		return false;
	}
	
	function create_ctaxonomy(){
		register_taxonomy(
			'apt_funnel',
			array( 'aptwhatsmsg','aptfunnelstage' ),
			array(
				'public' => true,
				'labels' => array( 'name' => 'Funis', 'singular_name' => 'Funil' )
			)
		);
		
		register_taxonomy(
			'apt_stage',
			array( 'aptwhatsmsg' ),
			array(
				'public' => true,
				'labels' => array( 'name' => 'Estágios', 'singular_name' => 'Estágio' )
			)
		);
	}
	
	function create_cpost() {
		
		register_post_type( 'aptwhatsmsg',
			array (
				'labels' =>
			array (
				'name' => 'Mensagens WhatsApp',
				'singular_name' => 'Mensagem WhatsApp',
				'add_new' => 'Adicionar Nova',
				'add_new_item' => 'Adicionar Nova Mensagem',
				'edit_item' => 'Editar',
				'new_item' => 'Nova Mensagem',
				'view_item' => 'Ver Mensagem',
				'search_items' => 'Procurar',
				'not_found' => 'Nenhuma mensagem encontrada',
				'not_found_in_trash' => 'Nenhuma mensagem encontrada na lixeira',
			),
			'supports' =>
				array (
					'title' => 'title',
				),
				//'flush_rewrite_rules' => false,
				//'capabilities' => 'edit_post',
				'description' => 'Configure novos servidores de email. Utilize uma instalação para cada funil.',
				'menu_position' => 100,
				'show_ui' => true,
				'public' => true,
				'show_in_menu' => true,
				'show_in_nav_menus' => false,
				'publicly_queryable' => true,
				'exclude_from_search' => true,
				'hierarchical' => false,
				'has_archive' => false,
				'rewrite' => true,
				'query_var' => false,
				'can_export' => true,
				'cf_columns' => '',
				'menu_icon' => 'dashicons-format-chat',
				'capability' => 'apt_aptwhatsmsg',
				'capability_type'     => array('apt_aptwhatsmsg','apt_aptwhatsmsgs'),
				'map_meta_cap'        => true,
			)
		);
		
		register_post_type( 'aptfunnelstage',
			array (
				'labels' =>
			array (
				'name' => 'Estágios de Funil',
				'singular_name' => 'Estágio de Funil',
				'add_new' => 'Adicionar Novo',
				'add_new_item' => 'Adicionar Novo Estágio',
				'edit_item' => 'Editar',
				'new_item' => 'Novo Estágio',
				'view_item' => 'Ver Estágio',
				'search_items' => 'Procurar',
				'not_found' => 'Nenhum Estágio encontrado',
				'not_found_in_trash' => 'Nenhum Estágio encontrado na lixeira',
			),
			'supports' =>
				array (
					'title' => 'title','editor' =>'editor',
				),
				//'flush_rewrite_rules' => false,
				//'capabilities' => 'edit_post',
				'description' => 'Configure novos servidores de email. Utilize uma instalação para cada funil.',
				'menu_position' => 100,
				'show_ui' => true,
				'public' => true,
				'show_in_menu' => true,
				'show_in_nav_menus' => false,
				'publicly_queryable' => true,
				'exclude_from_search' => true,
				'hierarchical' => false,
				'has_archive' => false,
				'rewrite' => true,
				'query_var' => false,
				'can_export' => true,
				'cf_columns' => '',
				'menu_icon' => 'dashicons-filter',
				'capability' => 'apt_funnelstage',
				'capability_type'     => array('apt_funnelstage','apt_funnelstages'),
				'map_meta_cap'        => true,
			)
		);
		
		register_post_type( 'aptemailserver',
			array (
				'labels' =>
			array (
				'name' => 'Email Servers',
				'singular_name' => 'Email Server',
				'add_new' => 'Adicionar Novo',
				'add_new_item' => 'Adicionar Novo Email Server',
				'edit_item' => 'Editar',
				'new_item' => 'Novo Email Server',
				'view_item' => 'Ver Email Server',
				'search_items' => 'Procurar',
				'not_found' => 'Nenhum Email Server encontrado',
				'not_found_in_trash' => 'Nenhum Email Server encontrado na lixeira',
			),
			'supports' =>
				array (
					'title' => 'title',
				),
				//'flush_rewrite_rules' => false,
				//'capabilities' => 'edit_post',
				'description' => 'Configure novos servidores de email. Utilize uma instalação para cada funil.',
				'menu_position' => 100,
				'show_ui' => true,
				'public' => true,
				'show_in_menu' => true,
				'show_in_nav_menus' => false,
				'publicly_queryable' => true,
				'exclude_from_search' => true,
				'hierarchical' => false,
				'has_archive' => false,
				'rewrite' => true,
				'query_var' => false,
				'can_export' => true,
				'cf_columns' => '',
				'menu_icon' => 'dashicons-email-alt',
				'capability' => 'apt_emailserver',
				'capability_type'     => array('apt_emailserver','apt_emailservers'),
				'map_meta_cap'        => true,
			)
		);
		flush_rewrite_rules(false);
		  
	}
}

?>