<?php

namespace apt\thewhale;

abstract class mail_server{
	
	protected $instance_id;
	protected $configs;
	
	function __construct($id){
		$this->instance_id = $id;
		$this->set_configs();
	}
	
	static function factory($id){
		//check type and create the proper class
		$post = get_post($id);
		
		if($post){
			$server_type = get_field("mauticfunnelint_email_system",$id);
			$class_to_call = "apt\\thewhale\\".$server_type;
			
			if(class_exists($class_to_call)){
				return new $class_to_call($id);
			}
		}
		
		return false;
	}
	
	function get_id(){
		return $this->instance_id;
	}
	
	abstract public function get_lead($search);
	abstract public function get_lead_by_id($id);
	abstract public function get_lead_all();
	abstract function get_owner();
	abstract function set_configs();
	abstract function change_stage($new_stage,$lead_id = null);
	abstract function do_add_tags($tags_array);
	abstract function create_user($user_data = array(),$post_data);
	abstract function create_lead($lead_data,$post_data);
	abstract function update_lead($lead_id,$update_array,$post_data);
	
	function add_tags($tags_array){
		$valid_tags = array();
		
		foreach($tags_array as $tag){
			
			if(empty($tag))
				continue;
			
			if(!in_array($tag,$valid_tags))
				$valid_tags[] = $tag;
		}
		
		$this->do_add_tags($valid_tags);
	}
}

?>