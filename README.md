Zender
======

A simple PHP class for interaction with Zend. Currently can destroy Job schedules, create jobs (from a URI with a CHRON command; defaults to JSON API server responses), and retreve existing job (either by name or Zend query) Additional features forthcoming.

Created specifically when I was learning (specifically the Job Queue) Zend Server (5.1) on Openshift... Which has a few **'gotchas'** for general use that have been addresed:


**You must pass the create_job function an associtative parameter array as its second argument.**

**You must bitshift the class constant 'status' by 1 to properly filter jobs by this value.**




**Functions**
-------------
  
	destroy_schedules()
	
Destroys all scheduled rules. Returns array with statuses of destroyed 	schedules.

	create_job(URI,name,persistent(bool or 'fetch'),schedule(chron command))
	
Creates a job, URI is the website/script (encode a get parameter if needed), the persistent flag doesnt really seem to work, but I am testing a 'fetch' functionality that will fetch the URI with file_get_contents() so a response is immediately available if the schedule has not yet run. Can be used in loops to quickly generate rules by providing a URI and name. This function will check to see that a job with the same passed 'name' does not exist before creating it and defaults to a chron schedule of every 15 mintutes.

	get_job(name/query array,output (default 'json') )

This will select the 'latest' (by ID) of the job matching the provided name (if its passed a string.) Otherwise try your own luck at generating your own queries (use the default query as an example of how to use the class constants to define sort options). The output variable defines what kind of data will be returned from the job - the default case is a JSON feed - the response is parsed for a javascript variable, and returned as a decoded_json php array/object. Override this value to allow the returning of the raw value stored in the job queue.
