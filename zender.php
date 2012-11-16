<?php

/*

	Created specifically when I was learning (specifically Jobs) Zend Server (5.1) on Openshift... Which
	has a few 'gotchas' for general use that have been addresed:
	
	 *You must pass the create_job function an associtative parameter array as its second argument.
	
	 *You must bitshift the class constant 'status' by 1 to properly filter jobs by this value.
	
	I found the provided documentation atrocious and lacking - nor could any 
	decent examples be used...
	
	That being said it works!
	
	functions
	
		destroy_schedules()
	
	Destroys all scheduled rules. Returns array with statuses of destroyed schedules.
	
		create_job(URI,name,persistent(bool or 'fetch'),schedule(chron command))
		
	Creates a job, URI is the website/script (encode a get parameter if needed), the persistent flag doesnt really seem to work, but I am testing a 'fetch' functionality that will fetch the URI with file_get_contents() so a response is immediately available if the schedule has not yet run. Can be used in loops to quickly generate rules by providing a URI and name. This	function will check to see that a job with the same passed 'name' does not exist before creating it and defaults to a chron schedule of every	15 mintutes.	
	
		get_job(name/query array,output (default 'json') )
	
	This will select the 'latest' (by ID) of the job matching the provided name (if its passed a string.) Otherwise try your own luck at generating your own queries (use the default query as an example of how to use the class constants to define sort options). The output variable defines what kind of data will be returned from the job - the default case is a JSON feed - the response is parsed for a javascript variable, and returned as a decoded_json php array/object. Override this value to allow the returning of the raw value stored in the job queue.
	

*/

class zender{

	public function __construct(){
		if(!isset($this->_queue))
			$this->_queue = new ZendJobQueue();
	}
	function destroy_schedules(){
	// actually destroys all schedules rules
		$this->queue_connect();
		$schedules = $this->_queue->getSchedulingRules();
		$schedule_ids = array();
		foreach($schedules as $loc=>$data){
			$schedule_ids[]=$data['id'];
		}
		
		if(!empty($schedule_ids)){
			$schedule_delete_response = array();
			foreach($schedule_ids as $sid){
				$schedule_delete_response []=$this->_queue->deleteSchedulingRule($sid);
				
			}
			// should return an array with ones .. optional ... consider returning true instead
			return $schedule_delete_response;
		}
		// no schedules to remove
		return false;
	}
	protected function create_job($url=null,$name,$params=array(),$persistent=false,$schedule="00,15,30,45 * * *"){
		// check jobs list for URL
		$r = $this->_queue->getJobsList(array('name'=>$name,'script'=>$url) );
		if($r == null){
			$job_id = $this->_queue->createHttpJob($url,$params, array ('name'=>$name,'persistent'=> ($persistent == 'fetch' ? false: $persistent), "schedule" => $schedule ) );
		}else{
		// job exists log that we are attempting to create a job that already exists ?
			;
		}
		if(isset($job_id)){	
		// return the 'actual call' via filegetcontents?
			if($persistent== 'fetch'){
			// 'fetch' is designed to immediately return something in addition to 'creating' a new schedule
			//	echo '<script type="text/javascript">console.log("fetching")</script>';
			// encode the params array too! possibly search uri for existing question mark and replace...question mark with and
				return json_decode(file_get_contents($url .( !empty($params) ? '?'. http_build_query($params) :''))); 
			}
			// add check of getJobStatus to return the value on create_job? allow
			return $this->_queue->getJobStatus($job_id);		
		}
	}
	
	
	protected function do_job($name,$query,$domain='',$params=false){
	// domain refers to the calling function to store multiple responses per each
	// name, needs a bit more work as additional response functions are supported
	
	// a not-so elegant solution if a param is not sent, cannot create job schedules on open shift that
	// do not have the second parameter set
	    if(empty($param) || !is_array($param))
	    	$param = array(''=>'');
	    
	    if($result = $this->get_job($domain . '_' . $name))
	    // return latest exising valid job from zend
	    	return $result;
	     // build the query, use prefetch...
	     // this should not create the same job per each 'user' ... 
	    $this->create_job($query,$domain . '_' . $name,$params,true);
		return false;
	}
	
	
	protected function get_job($query_array  = null,$output = 'json'){
		// ways to strip the header from the output
		$output = ($output == 'json'? "[" : false);
		if(!is_array($query_array) && !is_object($query_array) && $query_array != null){
		// look up by name
			if(!is_numeric($query_array))
			// provide with 'safe' default values to lookup by a 'name' - then we attempt to get the 'latest' by sorting by id, descending
				$query_array = array('name'=> $query_array,'status'=> 1<< ZendJobQueue::STATUS_COMPLETED,'sort_by'=> ZendJobQueue::SORT_BY_ID ,'sort_direction'=> ZendJobQueue::SORT_DESC,'count'=>1);	
		}
		// getJobsList doesn't support second parameter (to limit overall output) if/when count is provided?
		if($r = $this->_queue->getJobsList($query_array)){
			$r = $r[0]['output'];
			// should strip out MOST json ?? so long as this char is not in the header... hmmmmmm...
			if($output != false){
			// decode and strip json content (iuf header has a '[' i'm screweeed
				$r = explode($output,$r,2) and $r = $r[1];
				$r = json_decode($output.$r,true);				
				}
	
			return $r	;
		}
		return false;
	}
}