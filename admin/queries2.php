<?php
require('./include/global-vars2.php');
require('./include/global-functions2.php');
require('./include/menu.php');

LoadConfigFile();
if ($Config['Password'] != '') {  
  session_start();
  if (! Check_SessionID()) {
    header("Location: ./login.php");
    exit;
  }
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8" />
  <link href="./css/master.css" rel="stylesheet" type="text/css" />
  <link rel="icon" type="image/png" href="./favicon.png" />
  <script src="./include/menu.js"></script>
  <script src="./include/queries.js"></script>
  <title>NoTrack - DNS Stats</title>
</head>

<body>
<?php
ActionTopMenu();
draw_topmenu();
draw_sidemenu();
echo '<div id="main">'.PHP_EOL;

/************************************************
*Constants                                      *
************************************************/
DEFINE('DBNAME', 'ntrkdb');
DEFINE('DEF_FILTER', 'all');
DEFINE('DEF_SYSTEM', 'all');
DEFINE('DEF_SDATE', date("Y-m-d", time() - 172800));
DEFINE('DEF_EDATE', date("Y-m-d", time() - 86400));
DEFINE('ROWSPERPAGE', 200);

/************************************************
*Global Variables                               *
************************************************/
$page = 1;
$filter = DEF_FILTER;
$view = "livegroup";
$sort = 'DESC';
$sys = DEF_SYSTEM;

$datestart = DEF_SDATE;
$dateend = DEF_EDATE;
$sqltable = 'live';

/************************************************
*Arrays                                         *
************************************************/
$unsortedlog = array();
$sortedlog = array();
$syslist = array();

$FILTERLIST = array('all' => 'All Requests',
                    'allowed' => 'Allowed Only',
                    'blocked' => 'Blocked Only',
                    'local' => 'Local Only');

$VIEWLIST = array('livegroup', 'livetime', 'historicgroup', 'historictime');
$TLDBlockList = array();
$CommonSites = array();                          //Merge Common sites list with Users Suppress list
$COMMONSITESLIST = array('cloudfront.net',
                         'googleusercontent.com',
                         'googlevideo.com',
                         'cedexis-radar.net',
                         'gvt1.com',
                         'deviantart.net',
                         'deviantart.com',
                         'tumblr.com');
//CommonSites referres to websites that have a lot of subdomains which aren't necessarily relivent. In order to improve user experience we'll replace the subdomain of these sites with "*"
//cloudfront.net - Very popular CDN, hard to back trace originating site
//googleusercontent.com - Google+ and YouTube user content
//googlevideo.com - True links to YouTube videos
//cedexis-radar.net - Blocked tracker that uses a different subdomain per site they provide tracking services for
//gvt1.com - Google Play Store
//deviantart.net - Image download from deviatart
//deviantart.com - Each user has a different subdomain on deviantart.com
//tumblr.com - Each blog is on a different subdomain

/********************************************************************
 *  Add Date Vars to SQL Search
 *
 *  Params:
 *    None
 *  Return:
 *    SQL Query string
 */
function add_datestr() {
  global $sqltable, $filter, $sys, $datestart, $dateend;
  
  if ($sqltable == 'live') return '';
  
  $searchstr = ' WHERE ';
  if (($filter != DEF_FILTER) || ($sys != DEF_SYSTEM)) $searchstr = ' AND ';
  
  $searchstr .= 'log_time BETWEEN \''.$datestart.'\' AND \''.$dateend.' 23:59\'';
  
  return $searchstr;
}


/********************************************************************
 *  Add Filter Vars to SQL Search
 *
 *  Params:
 *    None
 *  Return:
 *    SQL Query string
 */
function add_filterstr() {
  global $filter, $sys;
  
  $searchstr = ' WHERE ';
  
  if (($filter == DEF_FILTER) && ($sys == DEF_SYSTEM)) return '';
  
  if ($sys != DEF_SYSTEM) {
    $searchstr .= 'sys = \''.$sys.'\'';
  }
  if ($filter != DEF_FILTER) {
    if ($sys != DEF_SYSTEM) $searchstr .= ' AND dns_result=';
    else $searchstr .= ' dns_result=';
    
    switch($filter) {
      case 'allowed':
        $searchstr .= '\'a\'';
        break;
      case 'blocked':
        $searchstr .= '\'b\'';
        break;
      case 'local':
        $searchstr .= '\'l\'';
        break;
    }
  }
  return $searchstr;        
}


/********************************************************************
 *  Count rows in Live table
 *  
 *  1. Attempt to load value from Memcache
 *  2. Check if same query is being run
 *  3. If that fails then run query
 *
 *  Params:
 *    None
 *  Return:
 *    None
 */
function count_rows($query) {
  global $livedb, $mem;
  
  $rows = 0;
  
  if ($mem->get('rows')) {                       //Does rows exist in memcache?
    if ($query == $mem->get('oldquery')) {       //Is this query same as old query?
      $rows = $mem->get('rows');                 //Use stored value      
      return $rows;
    }
  }
  
  if(!$result = $livedb->query($query)){
    die('There was an error running the query '.$livedb->error);
  }
  
  $rows = $result->fetch_row()[0];               //Extract value from array
  $result->free();    
  $mem->set('oldquery', $query, 0, 600);         //Save for 10 Mins
      
  return $rows;
}


/********************************************************************
 *  Draw Filter Box
 *  
 *  Params:
 *    None
 *  Return:
 *    None
 */
function draw_filterbox() {
  global $FILTERLIST, $syslist, $filter, $page, $sqltable, $sort, $sys, $view;
  global $datestart, $dateend;
  
  $hidden_date_vars = '';
  if ($sqltable == 'historic') $hidden_date_vars = '<input type="hidden" name="datestart" value="'.$datestart.'" /><input type="hidden" name="dateend" value="'.$dateend.'" />'.PHP_EOL;
  
  echo '<div class="sys-group">'.PHP_EOL;
  echo '<h5>DNS Queries</h5>'.PHP_EOL;
  echo '<div class="row"><div class="col-half">'.PHP_EOL;
  echo '<form method="get">'.PHP_EOL;
  echo '<input type="hidden" name="page" value="'.$page.'" />'.PHP_EOL;
  echo '<input type="hidden" name="view" value="'.$view.'" />'.PHP_EOL;
  echo '<input type="hidden" name="filter" value="'.$filter.'" />'.PHP_EOL;
  echo '<input type="hidden" name="sort" value="'.strtolower($sort).'" />'.PHP_EOL;
  echo $hidden_date_vars;
  echo '<span class="filter">System:</span><select name="sys" onchange="submit()">';
    
  if ($sys == DEF_SYSTEM) {
    echo '<option value="all">All</option>'.PHP_EOL;
  }
  else {
    echo '<option value="1">'.$sys.'</option>'.PHP_EOL;
    echo '<option value="all">All</option>'.PHP_EOL;
  }
  foreach ($syslist as $line) {
    if ($line != $sys) echo '<option value="'.$line.'">'.$line.'</option>'.PHP_EOL;
  }
  echo '</select></form>'.PHP_EOL;
  echo '</div>'.PHP_EOL;
  
  echo '<div class="col-half">'.PHP_EOL;
  echo '<form method="get">'.PHP_EOL;
  echo '<input type="hidden" name="page" value="'.$page.'" />'.PHP_EOL;
  echo '<input type="hidden" name="view" value="'.$view.'" />'.PHP_EOL;
  echo '<input type="hidden" name="sort" value="'.strtolower($sort).'" />'.PHP_EOL;
  echo '<input type="hidden" name="sys" value="'.$sys.'" />'.PHP_EOL;
  echo $hidden_date_vars;
  echo '<span class="filter">Filter:</span><select name="filter" onchange="submit()">';
  echo '<option value="'.$filter.'">'.$FILTERLIST[$filter].'</option>'.PHP_EOL;
  foreach ($FILTERLIST as $key => $line) {
    if ($key != $filter) echo '<option value="'.$key.'">'.$line.'</option>'.PHP_EOL;
  }
  echo '</select></form>'.PHP_EOL;
  echo '</div></div>'.PHP_EOL;
  
  if ($sqltable == 'historic') {
    echo '<div class="row">'.PHP_EOL;
    echo '<form method="get">'.PHP_EOL;
    echo '<input type="hidden" name="page" value="'.$page.'" />'.PHP_EOL;
    echo '<input type="hidden" name="view" value="'.$view.'" />'.PHP_EOL;
    echo '<input type="hidden" name="sort" value="'.strtolower($sort).'" />'.PHP_EOL;
    echo '<input type="hidden" name="filter" value="'.$filter.'" />'.PHP_EOL;
    echo '<input type="hidden" name="sys" value="'.$sys.'" />'.PHP_EOL;
    echo '<div class="col-half">'.PHP_EOL;
    echo '<span class="filter">Start Date: </span><input name="datestart" type="date" value="'.$datestart.'" onchange="submit()"/>'.PHP_EOL;
    echo '</div>'.PHP_EOL;
    echo '<div class="col-half">'.PHP_EOL;
    echo '<span class="filter">End Date: </span><input name="dateend" type="date" value="'.$dateend.'" onchange="submit()"/>'.PHP_EOL;
    echo '</div>'.PHP_EOL;
    echo '</form>'.PHP_EOL;
    echo '</div>'.PHP_EOL;
  }
  
  echo '</div>'.PHP_EOL;
}

function draw_viewbuttons() {
  global $sqltable, $view;

  echo '<div class="pag-nav float-right"><ul>'.PHP_EOL;
  if ($sqltable == 'live') {
    echo '<li class="active"><a href="?view=livegroup">Today</a></li>'.PHP_EOL;
    echo '<li><a href="?view=historicgroup">Historic</a></li>'.PHP_EOL;
  }
  else {
    echo '<li><a href="?view=livegroup">Today</a></li>'.PHP_EOL;
    echo '<li class="active"><a href="?view=historicgroup">Historic</a></li>'.PHP_EOL;
  }  
  if (($view == 'livetime') || ($view == 'historictime')) {
    echo '<li><a href="?view='.$sqltable.'group">Group</a></li>'.PHP_EOL;
    echo '<li class="active"><a href="?view='.$sqltable.'time">Time</a></li>'.PHP_EOL;    
  }
  elseif (($view == 'livegroup') || ($view == 'historicgroup')) {
    echo '<li class="active"><a href="?view='.$sqltable.'group">Group</a></li>'.PHP_EOL;
    echo '<li><a href="?view='.$sqltable.'time">Time</a></li>'.PHP_EOL;    
  }
  echo '</ul></div>'.PHP_EOL;
}
/********************************************************************
 *  Load Log Files
 *  
 *  1. Attempt to load TLDBlockList from Memcache
 *  2. If that fails then check if DomainQuickList file exists
 *  3. Read each line into TLDBlockList array and trim off \n
 *  4. Once loaded store TLDBlockList array in Memcache for 30 mins
 *
 *  Params:
 *    None
 *  Return:
 *    None
 */
/*function load_logfiles() {
  global $sortedlog, $unsortedlog, $mem;
  global $SortCol, $SortDir, $StartStr, $StartTime, $DateRange, $ExecTime, $View;
  
  $LoadList = true;                              //Assume Logs will need loading
  $SortList = true;                              //Assume Array will need sorting
  $memSaveTime = 600;                            //How long to hold data in memory

  //How long to hold data in memcache based on how far back user is searching
  //Shorter time search = lower retention of Memcache
  if (($StartStr == '') || ($StartStr == 'today')) $memSaveTime = 240;
  elseif ($StartTime >= $ExecTime - 300) $memSaveTime = 30;    //-5 Min
  elseif ($StartTime >= $ExecTime - 1500) $memSaveTime = 50;   //-15 Min
  elseif ($StartTime >= $ExecTime - 3600) $memSaveTime = 90;   //-1 hour
  elseif ($StartTime >= $ExecTime - 28800) $memSaveTime = 180; //-8 hours

  //Attempt to load SortedDomainList from Memcache
  $sortedlog = $mem->get('sortedlog');   
  if ($sortedlog) {                              //Has array loaded?
    if (($StartStr == $mem->get('StartStr')) && 
        ($DateRange == $mem->get('DateRange')) && 
        ($View == $mem->get('View'))) {
      if (($SortCol == $mem->get('SortCol')) && 
          ($SortDir == $mem->get('SortDir'))) {  //Check if search is same
        $SortList = false;
        $LoadList = false;      
      }
      else {
        $LoadList = false;                       //No need to load list
        $sortedlog = array();                    //Delete data in array     
      }
    }
    else {
      $mem->delete('StartStr');                  //Delete old variables from Memcache
      $mem->delete('SortCol');
      $mem->delete('SortDir');
      $mem->delete('DateRange');
      $mem->delete('unsortedlog');
      $mem->delete('sortedlog');
      $mem->delete('View');
      $sortedlog = array();                      //Delete data in array
    }    
  }
    
  if ($LoadList) {                               //Load domain list from file  
    //Are we loading Todays logs or Historic logs?
    if ($StartTime > (time() - 86400)) load_todaylog();
    else load_historiclogs();
    $mem->set('unsortedlog', $unsortedlog, 0, $memSaveTime);
  }
  else {                                         //Load domain list from memcache
  $unsortedlog = $mem->get('unsortedlog');
    if (!$unsortedlog) {                         //Something wrong, get it reloaded
      if ($StartTime > (time() - 86400)) load_todaylog();
      else load_historiclogs();
      $mem->set('unsortedlog', $unsortedlog, 0, $memSaveTime);
    }
  }

  if ($SortList) {                               //Sort Array of Domains from log file    
    if ($SortCol == 1) {
      if ($SortDir == 0) ksort($unsortedlog);
      else krsort($unsortedlog);
    }
    else {
      if ($SortDir == 0) arsort($unsortedlog);   //Sort array by highest number of hits
      else asort($unsortedlog);
    }
    
    $sortedlog = array_keys($unsortedlog);
    $mem->set('StartStr', $StartStr, 0, $memSaveTime);       //Store variables in Memcache
    $mem->set('SortCol', $SortCol, 0, $memSaveTime);
    $mem->set('SortDir', $SortDir, 0, $memSaveTime);
    $mem->set('DateRange', $DateRange, 0, $memSaveTime);
    $mem->set('sortedlog', $sortedlog, 0, $memSaveTime);
    $mem->set('View', $View, 0, $memSaveTime);
  }
  
  return null;
}
*/

/********************************************************************
 *  Load TLD Block List
 *  
 *  1. Attempt to load TLDBlockList from Memcache
 *  2. If that fails then check if DomainQuickList file exists
 *  3. Read each line into TLDBlockList array and trim off \n
 *  4. Once loaded store TLDBlockList array in Memcache for 30 mins
 *
 *  Params:
 *    None
 *  Return:
 *    None
 */
function load_tldblocklist() {
  global $TLDBlockList, $mem, $DomainQuickList;
  
  $TLDBlockList = $mem->get('TLDBlockList');
  if (! $TLDBlockList) {
    if (file_exists($DomainQuickList)) {          //Check if File Exists
      $FileHandle = fopen($DomainQuickList, 'r') or die('Error unable to open '.$DomainQuickList);
      while (!feof($FileHandle)) {
        $TLDBlockList[] = trim(fgets($FileHandle));
      }
      fclose($FileHandle);
      $mem->set('TLDBlockList', $TLDBlockList, 0, 1800);
    }
  }
  
  return null;
}


/********************************************************************
 *  Load Historic Logs
 *  
 *  1. Load relevant files from /var/log/notrack
 *  2. Add each line to $unsortedlog
 *
 *  Params:
 *    None
 *  Return:
 *    None
 */
/*function load_historiclogs() {
  global $DateRange, $StartTime, $View, $unsortedlog, $ExecTime;
  
  $LogFile = '';
  $url = '';
  $LD = $StartTime + 86400;                      //Log files get cached the following day, so we move the start date on by 86,400 seconds (24 hours)
  
  if ($View == 1) $pattern = '/^(.*)(\-|\+|1)$/';
  elseif ($View == 2) $pattern = '/^(.*)(\+|1)$/';
  elseif ($View == 3) $pattern = '/^(.*)(\-)$/';
  
  for ($i = 0; $i < $DateRange; $i++) {
    $LogFile = '/var/log/notrack/dns-'.date('Y-m-d', $LD).'.log';
    if (file_exists($LogFile)) {
      $FileHandle= fopen($LogFile, 'r');
      while (!feof($FileHandle)) {
        $line = trim(fgets($FileHandle));                  //Read Line of LogFile
        if (preg_match($pattern, $line, $matches) > 0) {          
          $url = simplify_url($matches[1]).$matches[2];          
          if (array_key_exists($url, $unsortedlog)) $unsortedlog[$url]++;
          else $unsortedlog[$url] = 1;
        }        
      }
    }  
  
    $LD = $LD + 86400;                           //Add per run of loop 24 Hours
    if ($LD > $ExecTime + 86400) {               //Don't exceed today      
      break;
    }
  }  
}
*/

/********************************************************************
 *  Pagination
 *  
 *  Draw up to 6 buttons
 *  Main [<] [1] [x] [x+1] [L] [>]
 *  Or   [ ] [1] [2] [>]
 *
 *  Params:
 *    rows
 *    $linktext = text for a href
 *  Return:
 *    None
 */
function pagination($totalrows, $linktext) {
  global $page;

  $numpages = 0;
  $currentpage = 0;
  $startloop = 0;
  $endloop = 0;
  
  if ($totalrows > ROWSPERPAGE) {                     //Is Pagination needed?
    $numpages = ceil($totalrows / ROWSPERPAGE);       //Calculate List Size
    
    //<div class="sys-group">
    echo '<div class="float-left pag-nav"><ul>'.PHP_EOL;
  
    if ($page == 1) {                            // [ ] [1]
      echo '<li><span>&nbsp;&nbsp;</span></li>'.PHP_EOL;
      echo '<li class="active"><a href="?page=1&amp;'.$linktext.'">1</a></li>'.PHP_EOL;
      $startloop = 2;
      if (($numpages > 3) && ($page < $numpages - 3)) $endloop = $page + 3;
      else $endloop = $numpages;
    }
    else {                                       // [<] [1]
      echo '<li><a href="?page='.($page-1).'&amp;'.$linktext.'">&#x00AB;</a></li>'.PHP_EOL;
      echo '<li><a href="?page=1&amp;'.$linktext.'">1</a></li>'.PHP_EOL;
      
      if ($numpages < 4) $startloop = 2;         // [1] [2] [3] [L]
      elseif (($page > 2) && ($page > $numpages -3)) $startloop = ($numpages - 2); //[1]  [x-1] [x] [L]
      else $startloop = $page;                   // [1] [x] [x+1] [L]
      
      if (($numpages > 3) && ($page < $numpages - 2)) $endloop = $page + 2; // [y] [y+1] [y+2]
      else $endloop = $numpages;                 // [1] [x-1] [y] [L]
    }    
    
    for ($i = $startloop; $i < $endloop; $i++) { //Loop to draw 2 buttons
      if ($i == $page) {
        echo '<li class="active"><a href="?page='.$i.'&amp;'.$linktext.'">'.$i.'</a></li>'.PHP_EOL;
      }
      else {
        echo '<li><a href="?page='.$i.'&amp;'.$linktext.'">'.$i.'</a></li>'.PHP_EOL;
      }
    }
    
    if ($page == $numpages) {                    // [Final] [ ]
      echo '<li class="active"><a href="?page='.$numpages.'&amp;'.$linktext.'">'.$numpages.'</a></li>'.PHP_EOL;
      echo '<li><span>&nbsp;&nbsp;</span></li>'.PHP_EOL;
    }    
    else {                                       // [Final] [>]
      echo '<li><a href="?page='.$numpages.'&amp;'.$linktext.'">'.$numpages.'</a></li>'.PHP_EOL;
      echo '<li><a href="?page='.($page+1).'&amp;'.$linktext.'">&#x00BB;</a></li>'.PHP_EOL;
    }	
    
  echo '</ul></div>'.PHP_EOL;
  //</div>
  }
}
/********************************************************************
 *  Search Systems
 *  
 *  1. Find unique sys values in table
 *
 *  Params:
 *    None
 *  Return:
 *    None
 */
function search_systems() {
  global $livedb, $mem, $syslist;
  
  $syslist = $mem->get('syslist');
  if (! $syslist) {
    if (! $result = $livedb->query('SELECT DISTINCT `sys` FROM `live` ORDER BY `sys`')) {
      die('There was an error running the query'.$livedb->error);
    }
    while($row = $result->fetch_assoc()) {       //Read each row of results
      $syslist[] = $row['sys'];                  //Add row value to $syslist
    }
    $result->free();
    $mem->set('syslist', $syslist, 0, 600);      //Save for 10 Mins
  }    
}

/********************************************************************
 *  Simplify URL
 *  
 *  1: Drop www (its unnecessary and not all websites use it now)
 *  2. Extract domain.tld, including double-barrelled domains
 *  3. Check if site is to be suppressed (present in $CommonSites)
 *
 *  Params:
 *    $url - URL To Simplify
 *  Return:
 *    Simplified URL
 */
function simplify_url($url) {  
  global $CommonSites;
  $simpleurl = '';
    
  if (substr($url,0,4) == 'www.') $simpleurl  = substr($url,4); 
  else $simpleurl  = $url;
  
  if (preg_match('/[A-Za-z0-9\-\_]{2,63}\.(gov\.|org\.|co\.|com\.)?[A-Za-z0-9\-]{2,63}$/', $simpleurl , $Match) == 1) {
    if (in_array($Match[0],$CommonSites)) return '*.'.$Match[0];
    else return $simpleurl ;
  }
 
  return $simpleurl;
}

/********************************************************************
 *  Show Live Group
 *  
 *  Show results from either Live or Historic table in Group order
 *
 *  Params:
 *    None
 *  Return:
 *    false when nothing found, true on success
 */
function show_group_view() {
  global $livedb, $sqltable, $page, $sort, $filter, $sys, $view, $Config, $TLDBlockList;
  global $datestart, $dateend;
  
  $i = (($page - 1) * ROWSPERPAGE) + 1;
  $rows = 0;
  $row_class = '';
  $action = '';
  $blockreason = '';
  
  $linkstr = htmlspecialchars('&filter='.$filter.'&sys='.$sys);
  if ($sqltable == 'historic') $linkstr .= htmlspecialchars('&datestart='.$datestart.'&dateend='.$dateend);
  
  $rows = count_rows('SELECT COUNT(DISTINCT `dns_request`) FROM `'.$sqltable.'`' .add_filterstr().add_datestr());
  $query = 'SELECT sys, dns_request, dns_result, COUNT(*) AS count FROM `'.$sqltable.'`'.add_filterstr().add_datestr().' GROUP BY dns_request ORDER BY count '.$sort.' LIMIT '.ROWSPERPAGE.' OFFSET '.(($page-1) * ROWSPERPAGE);
  
  if(!$result = $livedb->query($query)){
    die('There was an error running the query'.$livedb->error);
  } 
  
  if ($result->num_rows == 0) {                 //Leave if nothing found
    $result->free();
    echo 'Nothing Found';
    return false;
  }
  
  if ((($page-1) * ROWSPERPAGE) > $rows) $page = 1;
  
  echo '<div class="sys-group">'.PHP_EOL;
  pagination($rows, 'view='.$view.'&amp;sort='.strtolower($sort).$linkstr);
  draw_viewbuttons();
  
  echo '<table id="query-group-table">'.PHP_EOL;
  
  echo '<tr><th>#</th><th>Site</th><th>Action</th><th>Requests<a href="?page='.$page.'&amp;view='.$view.'&amp;sort=desc'.$linkstr.'">&#x25BE;</a><a href="?page='.$page.'&amp;view='.$view.'&amp;sort=asc'.$linkstr.'">&#x25B4;</a></th></tr>'.PHP_EOL;  
  
  while($row = $result->fetch_assoc()) {         //Read each row of results
    $action = '<a target="_blank" href="'.$Config['SearchUrl'].$row['dns_request'].'"><img class="icon" src="./images/search_icon.png" alt="G" title="Search"></a>&nbsp;<a target="_blank" href="'.$Config['WhoIsUrl'].$row['dns_request'].'"><img class="icon" src="./images/whois_icon.png" alt="W" title="Whois"></a>&nbsp;';
    if ($row['dns_result'] == 'A') {
      $row_class='';
      $action .= '<span class="pointer"><img src="./images/report_icon.png" alt="Rep" title="Report Site" onclick="ReportSite(\''.$row['dns_request'].'\', false)"></span>';
    }
    elseif ($row['dns_result'] == 'B') {
      $row_class = ' class="blocked"';
      if (preg_match('/([\w\d\-_]+)$/', $row['dns_request'],  $matches) > 0) {        
        if (in_array('.'.$matches[1], $TLDBlockList)) {
          $blockreason = '<p class="small">.'.$matches[1].' Blocked by Top Level Domain List</p>';          
        }
        else {
          $action .= '<span class="pointer"><img src="./images/report_icon.png" alt="Rep" title="Report Site" onclick="ReportSite(\''.$row['dns_request'].'\', true)"></span>';
        }
      }
      elseif (!filter_var($row['dns_request'], FILTER_VALIDATE_IP) === false) {
        $row_class = ' class="invalid"';
        $blockreason = '<p class="small">IP Requested</p>';
      }        
    }
    elseif ($row['dns_result'] == 'L') {
      $row_class = ' class="local"';
      $action = '&nbsp;';
    }
    
    echo '<tr'.$row_class.'><td>'.$i.'</td><td>'.$row['dns_request'].$blockreason.'</td><td>'.$action.'</td><td>'.$row['count'].'</td></tr>'.PHP_EOL;
    $blockreason = '';
    $i++;
  }
  
  echo '</table>'.PHP_EOL;
  echo '</div>'.PHP_EOL;
  $result->free();

  return true;
}

/********************************************************************
 *  Show Live Time
 *  
 *  Show results from Live table in Time order
 *
 *  Params:
 *    None
 *  Return:
 *    false when nothing found, true on success
 */
function show_live_time() {
  global $livedb, $page, $sort, $filter, $sys, $view, $Config, $TLDBlockList;
  
  $rows = 0;
  $row_class = '';
  $action = '';
  $blockreason = '';
    
  $rows = count_rows('SELECT COUNT(*) FROM live'.add_filterstr());
  if ((($page-1) * ROWSPERPAGE) > $rows) $page = 1;
    
  $query = 'SELECT * FROM live'.add_filterstr(). ' ORDER BY log_time '.$sort.' LIMIT '.ROWSPERPAGE.' OFFSET '.(($page-1) * ROWSPERPAGE);
  
  if(!$result = $livedb->query($query)){
    die('There was an error running the query'.$livedb->error);
  }
  
  if ($result->num_rows == 0) {                 //Leave if nothing found
    $result->free();    
    return false;
  }
  
  echo '<div class="sys-group">'.PHP_EOL;
  pagination($rows, 'view='.$view.'&amp;sort='.strtolower($sort).'&amp;filter='.$filter.'&amp;sys='.$sys);
  draw_viewbuttons();
  
  echo '<table id="query-time-table">'.PHP_EOL;
  echo '<tr><th>Time<a href="?page='.$page.'&amp;view='.$view.'&amp;sort=desc&amp;filter='.$filter.'&amp;sys='.$sys.'">&#x25BE;</a><a href="?page='.$page.'&amp;view='.$view.'&amp;sort=asc&amp;filter='.$filter.'&amp;sys='.$sys.'">&#x25B4;</a></th><th>System</th><th>Site</th><th>Action</th></tr>'.PHP_EOL;  
  
  while($row = $result->fetch_assoc()) {         //Read each row of results
    $action = '<a target="_blank" href="'.$Config['SearchUrl'].$row['dns_request'].'"><img class="icon" src="./images/search_icon.png" alt="G" title="Search"></a>&nbsp;<a target="_blank" href="'.$Config['WhoIsUrl'].$row['dns_request'].'"><img class="icon" src="./images/whois_icon.png" alt="W" title="Whois"></a>&nbsp;';
    if ($row['dns_result'] == 'A') {
      $row_class='';
      $action .= '<span class="pointer"><img src="./images/report_icon.png" alt="Rep" title="Report Site" onclick="ReportSite(\''.$row['dns_request'].'\', false)"></span>';
    }
    elseif ($row['dns_result'] == 'B') {
      $row_class = ' class="blocked"';
      if (preg_match('/([\w\d\-_]+)$/', $row['dns_request'],  $matches) > 0) {
        if (in_array('.'.$matches[1], $TLDBlockList)) {
          $blockreason = '<p class="small">.'.$matches[1].' Blocked by Top Level Domain List</p>';          
        }
        else {
          $action .= '<span class="pointer"><img src="./images/report_icon.png" alt="Rep" title="Report Site" onclick="ReportSite(\''.$row['dns_request'].'\', true)"></span>';
        }
      }
      elseif (!filter_var($row['dns_request'], FILTER_VALIDATE_IP) === false) {
        $row_class = ' class="invalid"';
        $blockreason = '<p class="small">IP Requested</p>';
      }        
    }
    elseif ($row['dns_result'] == 'L') {
      $row_class = ' class="local"';
      $action = '&nbsp;';
    }
    
    echo '<tr'.$row_class.'><td>'.substr($row['log_time'], 11).'</td><td>'.$row['sys'].'</td><td>'.$row['dns_request'].$blockreason.'</td><td>'.$action.'</td></tr>'.PHP_EOL;
    $blockreason = '';
  }
  
  echo '</table>'.PHP_EOL;
  echo '</div>'.PHP_EOL;
  $result->free();

  return true;
}

/********************************************************************
 *  Show Historic Time
 *  
 *  Show results from Historic table in Time order
 *
 *  Params:
 *    None
 *  Return:
 *    false when nothing found, true on success
 */
function show_historic_time() {
  global $livedb, $page, $sort, $filter, $sys, $view, $datestart, $dateend, $Config, $TLDBlockList;
  global $datestart, $dateend;
  
  $rows = 0;
  $row_class = '';
  $action = '';
  $blockreason = '';  
    
  $rows = count_rows('SELECT COUNT(*) FROM historic'.add_filterstr().add_datestr());
  if ((($page-1) * ROWSPERPAGE) > $rows) $page = 1;
    
  $query = 'SELECT * FROM historic'.add_filterstr().add_datestr(). ' ORDER BY log_time '.$sort.' LIMIT '.ROWSPERPAGE.' OFFSET '.(($page-1) * ROWSPERPAGE);
  
  if(!$result = $livedb->query($query)){
    die('There was an error running the query'.$livedb->error);
  }
  
  if ($result->num_rows == 0) {                 //Leave if nothing found
    $result->free();
    echo "Nothing found for the selected dates";
    return false;
  }
  
  echo '<div class="sys-group">'.PHP_EOL;
  pagination($rows, htmlspecialchars('view='.$view.'&sort='.strtolower($sort).'&filter='.$filter.'&sys='.$sys.'&datestart='.$datestart.'&dateend='.$dateend));
  draw_viewbuttons();
  
  echo '<table id="query-time-table">'.PHP_EOL;
  echo '<tr><th>Time<a href="?'.htmlspecialchars('page='.$page.'&view='.$view.'&sort=desc&filter='.$filter.'&sys='.$sys.'&datestart='.$datestart.'&dateend='.$dateend).'">&#x25BE;</a><a href="?'.htmlspecialchars('page='.$page.'&view='.$view.'&sort=asc&filter='.$filter.'&sys='.$sys.'&datestart='.$datestart.'&dateend='.$dateend).'">&#x25B4;</a></th><th>System</th><th>Site</th><th>Action</th></tr>'.PHP_EOL;  
  
  while($row = $result->fetch_assoc()) {         //Read each row of results
    $action = '<a target="_blank" href="'.$Config['SearchUrl'].$row['dns_request'].'"><img class="icon" src="./images/search_icon.png" alt="G" title="Search"></a>&nbsp;<a target="_blank" href="'.$Config['WhoIsUrl'].$row['dns_request'].'"><img class="icon" src="./images/whois_icon.png" alt="W" title="Whois"></a>&nbsp;';
    if ($row['dns_result'] == 'A') {
      $row_class='';
      $action .= '<span class="pointer"><img src="./images/report_icon.png" alt="Rep" title="Report Site" onclick="ReportSite(\''.$row['dns_request'].'\', false)"></span>';
    }
    elseif ($row['dns_result'] == 'B') {
      $row_class = ' class="blocked"';
      $action .= '<span class="pointer"><img src="./images/report_icon.png" alt="Rep" title="Report Site" onclick="ReportSite(\''.$row['dns_request'].'\', true)"></span>';            
    }
    elseif ($row['dns_result'] == 'L') {
      $row_class = ' class="local"';
      $action = '&nbsp;';
    }
    
    echo '<tr'.$row_class.'><td>'.$row['log_time'].'</td><td>'.$row['sys'].'</td><td>'.$row['dns_request'].$blockreason.'</td><td>'.$action.'</td></tr>'.PHP_EOL;
    $blockreason = '';
  }
  
  echo '</table>'.PHP_EOL;
  echo '</div>'.PHP_EOL;
  $result->free();

  return true;
}

//Main---------------------------------------------------------------

$livedb = new mysqli(SERVERNAME, USERNAME, PASSWORD, DBNAME);

search_systems();

if (isset($_GET['page'])) {
  if (is_numeric($_GET['page'])) $page = $_GET['page'];
}

if (isset($_GET['filter'])) {
  if (array_key_exists($_GET['filter'], $FILTERLIST)) $filter = $_GET['filter'];
}

if (isset($_GET['sort'])) {
  if ($_GET['sort'] == 'asc') $sort = 'ASC';
}

if (isset($_GET['sys'])) {
  if (in_array($_GET['sys'], $syslist)) $sys = $_GET['sys'];
}

if (isset($_GET['view'])) {  
  if (in_array($_GET['view'], $VIEWLIST)) $view = $_GET['view'];
  if (($view == 'historicgroup') || ($view == 'historictime')) $sqltable = 'historic';
}

if (isset($_GET['datestart'])) {
  if (preg_match('/^20[0-9][0-9]\-[0-1][0-9]\-[0-3][0-9]$/', $_GET['datestart']) > 0) $datestart = $_GET['datestart'];
}
if (isset($_GET['dateend'])) {
  if (preg_match('/^20[0-9][0-9]\-[0-1][0-9]\-[0-3][0-9]$/', $_GET['dateend']) > 0) $dateend = $_GET['dateend'];
  else echo "fail";
}

if ($sqltable == 'historic') {
  if (strtotime($dateend) > time()) $dateend = DEF_EDATE;
  if (strtotime($datestart) > strtotime($dateend)) {
    $datestart = DEF_SDATE;
    $dateend = DEF_EDATE;
  }
}

if ($Config['bl_tld'] == 1) load_tldblocklist(); //Load TLD Blocklist if being used

draw_filterbox();

if ($view == 'livetime') {
  show_live_time();
}
elseif ($view == 'livegroup') {
  show_group_view();
}
elseif ($view == 'historictime') {
  show_historic_time();
}
elseif ($view == 'historicgroup') {
  show_group_view();
}

$livedb->close();

?>
</div>

<div id="scrollup" class="button-scroll" onclick="ScrollToTop()"><img src="./svg/arrow-up.svg" alt="up"></div>
<div id="scrolldown" class="button-scroll" onclick="ScrollToBottom()"><img src="./svg/arrow-down.svg" alt="down"></div>

<div id="stats-box">
<div class="dialog-bar">Report</div>
<span id="sitename">site</span>
<span id="statsmsg">something</span>
<span id="statsblock1"><a class="button-blue" href="#">Block Whole</a> Block whole domain</span>
<span id="statsblock2"><a class="button-blue" href="#">Block Sub</a> Block just the subdomain</span>
<form name="reportform" action="https://quidsup.net/notrack/report.php" method="post" target="_blank">
<input type="hidden" name="site" id="siterep" value="none">
<span id="statsreport"><input type="submit" value="Report"></span>
<!--<span id="statsreport"><a class="button-blue" href="#">Report</a></span>-->
</form>
<br />
<div class="centered"><h6 class="button-grey" onclick="HideStatsBox()">Cancel</h6></div>
<div class="close-button" onclick="HideStatsBox()"><img src="./svg/button_close.svg" onmouseover="this.src='./svg/button_close_over.svg'" onmouseout="this.src='./svg/button_close.svg'" alt="close"></div>
</div>

</body>
</html>
