<html>
<head>
<title>Openshift Zend Job+Mongo Example</title>
</head>
<body>
<?php 
/*
	Openshift Zend
	Using Zend Job Scheduler and Mongo
	
	--What This demonstrates--
		How to Connect to a ZendJobQueue in openshift
		How to Connect to MongoDB in openshift
		How to digest a public facing JSON returning API
		How to Cache responses in JSON from an API using Mongo/ZendJobQueue
		How to Stagger Chron job times to avoid too many jobs from executing in the same time slot
	
	-- Create new App of 'Zend' Type
	-- Add Mongo Gear
	-- Clone Repo - add this script - commit - and run with the $obj->build_jtv_schedules() uncommented.
	   Wait until the next schedule occurs (around upto 15 mintutes)
	
	This simple class connects to the justin.tv public api to cache (JSON) continually
	every 15 mintutes with Zend Jobs and Mongo Cache. These jobs can be queried by login names, which are (the queries) cached with mongo. Each schedule (up to 14 schedules) is staggered to occur one mintute apart. These
	jobs are stored (serialized) inside an openshift mongo gear and checked against a 'timestamp' and continually
	refreshed against the most recent job.
	
		Querying a Job from mongo around  - around 70-600ms (depending on openshift server load and query complexity)
		Loading A cached query from mongo (2-8 ms)
	
	
	
	Complete instructions are throughout the script, with complete use at the bottom.
	
	To-do
		Test out 'prefetch' to store the jobs to mongo while it attempts to load after build_jtv_schedules
		Create class constants to define category defaults and others, and class methods to modify these values?
		Function to delete 'really old' cache (query) entries. 
		Function that creates job that updates mongo queries within this script (local zend job)
		
	**WARNING**	
		May be possible for people to fill up the server by issuing a bunch of searches(if you pass values from a form or $_GET parameter); but jobs are cached so its not terrible; New searches take around 200-600 ms to query but varys greatly depending on server load (on open shift).

*/

// stats reporting (script exec. time)
$time = microtime(); 
$time = explode(" ", $time); 
$time = $time[1] + $time[0]; 
$start = $time; 

require('../zender.php');
class justin_tv_api extends zender{
	public function __construct($mongo='openshift'){
		// variable that kills output if any are encountered.
		// Connect to default Zend Job queue via parent class 'zender'
		parent::__construct();
		// connect to openshift
		if($mongo== 'openshift'){
		// ** TO DO support remote and local connections	
		// Getenv vars and connect .. note OPENSHIFT_NOSQL in previous versions
		// is now OPENSHIFT_MONGODB
			$localhost = getenv('OPENSHIFT_MONGODB_DB_HOST');
			$port = getenv('OPENSHIFT_MONGODB_DB_PORT');
			$appname = getenv('OPENSHIFT_APP_NAME');
			$username = getenv('OPENSHIFT_MONGODB_DB_USERNAME');
			$password = getenv('OPENSHIFT_MONGODB_DB_PASSWORD');
			$m = new Mongo("mongodb://$username:$password@$localhost:$port");
			// select database name 
			$this->mongo_db = $m->justin_tv_api;
			
			if(isset($_GET['build_jobs'])){
			// this is a somewhat timeconsuming function to run. But will not recreate /overwrite
			// existing schedules/jobs  Probably disable in production.
				echo 'building schedules hang tight';
				$this->build_jtv_schedules();
			}
			// connect via mongodb url syntax
		}
	}

	public function mongo_save($key,$data,$domain='jtv'){
	// converts objects to assoc. arrays. May cause problems on retreval
		if(isset($this->mongo_db)){
			$collection = $this->mongo_db->$domain;
			// adding key to record for easier retreval via string versus mongoid
			$data_2['_id'] = $key;
			$data_2['d'] = serialize($data);
			// timestamp consider doing base conversion to store as string while reducing data use.
			$data_2['ts'] = time();
			// to avoid storing overly complex objects.. still is a bit complex may consider serialization
			// ** TO DO check if id exists and update if different
			return $this->mongo_db->$domain->save($data_2);
		}
	}
	
	function mongo_get($key,$domain='jtv'){
	// Retreve via stored _id of '$key' in collection '$domain'
	// we use a '$gt' is time less than 3000 ... number should be put in a config class as a const
	// this will be fine if the time stamp lines up with the zend job .. but otherwise there might be some sort of lag
	// based on when the record itself updates (or at worse you'll have a record not update with something different...
	// for probably at least one iteration before retreving the appropriate value
		if(isset($this->mongo_db)){
			$data = $this->mongo_db->$domain->findOne(array('_id'=>$key, 'ts'=> array('$gt'=> time() - (15 * 60) ) ), array('d') ) ;
			$data = (isset($data['d'])? unserialize($data['d']): false);
			return $data;
		}
	}


	function jtv_related($query,$category='featured',$totalRecords=300){
		$results = array();
		// number of records per page - this is tied to the name of the key
		$record_limit = 100;	
		
		$query_check = (is_array($query) ? implode('_',$query): $query) . $totalRecords;
		
		// anonymous function (php 5.3)
		// determines wether stream matches query and returns the html string in the for loop below
		$fVal = function($string){
				return strtolower(str_replace(array('.', ' ', '(',')','@','!','#'),array(array_fill(0,7, '_')),$string));
		};
		// can search for the (string) $query inside of channel, or override and pass straight
		// thru if $format != html (or false)
		$html_channel = 
				function($channel,$query,$format='html'){
					$login = (is_object($channel)? $channel->login : $channel['login']);
					if($query == false || strpos($login,$query) !== false ){
						if(!is_object($channel)){
							$screen_cap = $channel['screen_cap_url_small'];
							$views_count = $channel['views_count'];
							$status = $channel['status'];
						}else{
							$screen_cap = $channel->screen_cap_url_small;
							$views_count = $channel->views_count;
							$status = $channel->status;
						}	
						return ($format == 'html' ? '<li><img src="'. $screen_cap .'"/><h4>'.   $login . '</h4><em>'. $views_count.'</em><p>'. $status .'</p></li>' : array('login'=>$login,'screen_cap_url_small'=>$screen_cap,'views_count'=>$views_count,'status'=>$status) );
					}
			};		
		
		if($query_check_data =  $this->mongo_get($fVal($query_check),$category. '_cache')){
			echo "\n".'<script type="text/javascript">console.log("from query from mongo cache");</script>';
			foreach($query_check_data as $key=>$line){
				if(trim($key) != '')
					$results[]=$html_channel($line,false);
			}
			return $results;
		}
		else{
			// this is the simple array that goes into mongo instead of raw html
			$related_2 = array();	
			for ($i = 0; $i < $totalRecords; $i = $i+$record_limit) {
				$key_name = "jtv_$category".'_'."$record_limit"."_$i" ;
				if($record = $this->mongo_get($key_name) ){
					$r = $record;
					// use to trigger the html closure to return html
				}else{
					// get the existing job (if it exists, and completed)
					$r = $this->get_job($key_name);
					if($r != null && !empty($r) ) {
						// if job contains 'something' save to mongo
						$this->mongo_save($key_name,$r);
					}
				}
				if($r != null && !empty($r) ){
					// begin processing each item in the returned job to see if it query terms exist within channel->login 
					// using strpos()
					foreach($r as $loc=>$rstream){
					// Workaround (temporary, and not elegant) objects from the job queue are objects,
					// but from mongo they become an assoc. array
						if(is_array($rstream)){
							$slogin = $rstream['channel']['login'];
							$channel = $rstream['channel'];
						}
						elseif(!is_object($rstream)){
							$slogin = $rstream->channel->login;
							$channel = $rstream->channel;
						}	
						if(!isset($related[$slogin]) && !is_array($query) && $string_pos = $html_channel($rstream['channel'],$query,false) && $related[$slogin] && $string_pos !== false){
							$related[$slogin] = $string_pos;
						}elseif(!isset($related[$slogin])){
							foreach($query as $term){
								if($string_pos = $html_channel($rstream['channel'],$term,false))
									$related[$slogin] = $string_pos;
							}
						}
					}
				}
			}		
		}
		// return matching terms, or an empty array.. hopefully
		$this->mongo_save($fVal($query_check),$related,$category . '_cache');
		// run same thing again on related ?? to display properly as html ..
		// convert related to HTML to display
		foreach($related as $line){
			$related_html[]=$html_channel($line,false);
		}
		return (isset($related_html) ?$related_html: false);
	}
	
	
	public function build_jtv_schedules($category='featured',$totalRecords=200,$record_limit=100,$lang='en'){
		// creating variable for returned array
		$response = array();
		// closure for padding numbers less than 10 with an extra zero
		$pad = function($i){ return ((int) $i <10 ?str_pad((int) $i,2,"0",STR_PAD_LEFT):$i) ;};
		// for generating the offsets ($i), jtv API does 100 records at a time max
		for ($i = 0; $i < $totalRecords; $i = $i+$record_limit) {
			// build the URL for each job
			$url = "http://api.justin.tv/api/stream/list.json?category=$category&limit=$record_limit&offset=$i&lang=$lang";
			// check if the job exists (at least the url ...) if it does then we return true (or end) out of loop ?
			
			// attempt to stagger job times to avoid serious hit to server 
			// using math.. a lot of it was just trial an error .. works as well as
			// time permits, staggers jobs 15 mintutes one mintute apart, works fine until you get 14-15 levels
			// deep into a loop, but will attempt to correct it (but you will have multiple jobs running in the same time slots)
			if($i == 0){
			// just in case this needs to be a tad bit better...
				$increment = 0;
			}elseif($increment = ($record_limit/$i) % 10 + $increment * 2){
				// probably use the total records and record limit to calculate a better offset
				// otherwise create an array with the existing chron times
				$increment = ((int) $increment > 15 ?  ($i / ($record_limit % $totalRecords))  : $increment);
				
			}
			$t1 = $pad($increment);
			$t2 = 15 + $increment;
			$t3 = 30 + $increment;
			$t4 = 45 + $increment;
			if($t3 > 44){
			// this is a band aid and protects against the last two fields having numbers greater
			// than intended, this wont happen unless you have around 600+ records
				$t3 = $pad($t3 % $i);
			}
			if($t4 > 59){
				$t4 = $pad($i % $t4);
			}
			
			$response []=$this->create_job($url,"jtv_$category".'_'."$record_limit"."_$i" ,array(''=>''),false,"$t1,$t2,$t3,$t4 * * *");
		}
		return $response;
	}
}

// Make JTV object
$obj= new justin_tv_api();
// Issue command to build first jobs (only needs issuance once;)
//$obj->destroy_schedules();
//$obj->build_jtv_schedules();

echo '<form method="GET">
<fieldset>
<label>Enter channel login name filters seperated by spaces, Searching Featured English Channels on justin.tv</label>
<input type="text" name="q" placeholder="Enter Terms"'.(isset($_GET['q'])?' value="'.$_GET['q'].'" ':'').'/>
</fieldset>
<input type="submit"/>
</form> ';
if(isset($_GET['q'])){
	$q = explode(' ',$_GET['q']);
	
	if($q == 1){
		$q =$q[1];
	}
	// jtv_related takes a string value, or an array value and searches the channel->login although additional fields could be added using strpos
	
	echo '<ul>' . implode("\n",$obj->jtv_related($q)) . '</ul>';
		
}
// stats reporting stuff

$time = microtime(); 
$time = explode(" ", $time); 
$time = $time[1] + $time[0]; 
$finish = $time; 
$totaltime = (round($finish - $start,3)  * 1000); 

echo "\n".'<div id="stats">'.round((memory_get_usage() / 1024),1) . "(k) $totaltime (ms)</div>";
?>
</body>
</html>