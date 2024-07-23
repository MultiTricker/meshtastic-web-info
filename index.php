<?php

//error_reporting(E_ALL);
//ini_set("display_errors", 1);

//////////////////////////////////////////////
/// Config
//////////////////////////////////////////////

// Page name and heading
$pageName = "📡 roudnice.czmesh";

// Incoming file with 'meshtastic --info' data is allowed from IP address:
$allowedIPAddress = "";

// Database connection
$dbServer = "localhost";
$dbName = "c1mesh";
$dbUser = "c1mesh";
$dbPass = "MojeMeshkoJeProMeVsechno";

//////////////////////////////////////////////
/// DB schema
//////////////////////////////////////////////

/*
CREATE TABLE `Nodes` (
  `NodeId` varchar(20) NOT NULL,
  `NodeData` text NOT NULL,
  PRIMARY KEY (`NodeId`),
  UNIQUE KEY `NodeIdUnique` (`NodeId`),
  KEY `NodeIdIndex` (`NodeId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
*/

/////////////////////////////////////////////////
/// Functions
/////////////////////////////////////////////////

function calculateDistance($latitude1, $longitude1, $latitude2, $longitude2) {
    $earth_radius = 6371000;

    $dLat = deg2rad($latitude2 - $latitude1);
    $dLon = deg2rad($longitude2 - $longitude1);

    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($latitude1)) * cos(deg2rad($latitude2)) *
         sin($dLon/2) * sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));

    $distance = $earth_radius * $c;

    return $distance; // in meters
}

/////////////////////////////////////////////////
// File to save?
/////////////////////////////////////////////////

$file = __DIR__ . "/raw-info-data.txt";

// Is there file in array $_FILES send to script with name "raw-info-data.txt"?
if((isset($_FILES['file']['tmp_name'])) OR isset($_GET['regenerate']))
{
    if(!empty($allowedIPAddress) AND filter_var($allowedIPAddress, FILTER_VALIDATE_IP) AND $_SERVER['REMOTE_ADDR'] != $allowedIPAddress)
    {
        die("You are not allowed to run this script from IP address $_SERVER[REMOTE_ADDR].");
    }

    // Save the file
    if(isset($_FILES['file']['tmp_name']))
    {
        move_uploaded_file($_FILES['file']['tmp_name'], $file);
    }
    //exit;

    // MySQL connect to DB c1mesh
    $mysqli = new mysqli($dbServer, $dbUser, $dbPass, $dbName);
    $mysqli->query("SET NAMES utf8");

    /////////////////////////////////////////////////
    // Read data to process
    /////////////////////////////////////////////////

    // Read and show the data
    $data = file_get_contents($file);

    // Hide possibly sensitive info from output
    $data = str_replace("large4cats", "******", $data);

    // From $data, get JSON between string "Nodes in mesh:" and "Channels"
    preg_match('/Nodes in mesh:(.*)Preferences/s', $data, $matches);

    // Decode JSON into associative array
    $nodes = json_decode($matches[1], true);

    // Decode Metadata
    preg_match('/Metadata:(.*)Nodes in mesh/s', $data, $matches);
    $metadata = json_decode($matches[1], true);

    // Is there JSON error? Show it!
    if(json_last_error() !== JSON_ERROR_NONE)
    {
        echo "JSON error: " . json_last_error_msg();
    }

    foreach($nodes as $node)
    {
        $mysqli->query("INSERT INTO Nodes(NodeId, NodeData) 
                              VALUES(\"{$node['user']['id']}\",
                                     '{$mysqli->real_escape_string(json_encode($node))}') 
                              ON DUPLICATE KEY UPDATE NodeData = '{$mysqli->real_escape_string(json_encode($node))}';");
    }

    /////////////////////////////////////////////////
    // Page start
    /////////////////////////////////////////////////

    // Calculate last update in minutes
    $lastUpdateTime = date("H:i", filemtime($file));
    $lastUpdateDate = date("j. n. Y", filemtime($file));

    $htmlPage = "<html>
    <head>
    <title>$pageName</title>
    </head>
    <body>
    
    <h1>$pageName</h1>
    
    <span class='notSoBright'>Last update:</span> <span title='$lastUpdateDate'>" . $lastUpdateTime . "</span> (refresh */15 minutes) <span class='notSoBright'>|</span> 
    <span class='notSoBright'>FW:</span> " . $metadata['firmwareVersion'] . " <span class='notSoBright'>|</span>
    <span class='notSoBright'>Role:</span> " . $metadata['role'] . " <span class='notSoBright'>|</span>
    <span class='notSoBright'>HW:</span> " . $metadata['hwModel'];

    $totalNodesInDB = $mysqli->query("SELECT COUNT(*) FROM Nodes")->fetch_row()[0];

    $htmlPage .= "<h2>Nodes ($totalNodesInDB) <span id='nodeHistoryCountStats'></span></h2>";

    // Print the array
    //print_r($nodes);

    // In array, node lookes like this:
    /*
      "!bdf0a954": {
        "num": 3186665812,
        "user": {
          "id": "!bdf0a954",
          "longName": "TvojeMama T-BEAM2",
          "shortName": "tvm2",
          "macaddr": "7c:9e:bd:f0:a9:54",
          "hwModel": "TBEAM"
        },
        "position": {
          "latitudeI": 503612380,
          "longitudeI": 142495786,
          "altitude": 196,
          "time": 1716487780,
          "latitude": 50.361238,
          "longitude": 14.2495786
        },
        "snr": -3.5,
        "lastHeard": 1716487778,
        "deviceMetrics": {
          "batteryLevel": 96,
          "voltage": 4.116,
          "channelUtilization": 1.6433334,
          "airUtilTx": 1.3251388,
          "uptimeSeconds": 22576
        },
        "hopsAway": 1
            )
     */

    //////////////////////////////////////////
    // Array for statistics how many nodes
    // were active in last 1, 4, 8, 24 hours
    //////////////////////////////////////////

    $nodeHistoryCountStats = [
        '1h' => 0,
        '4h' => 0,
        '8h' => 0,
        '24h' => 0
    ];

    //////////////////////////////////
    // Print all nodes into table
    //////////////////////////////////

    $htmlPage .= "
    <table id='meshtable'>
    <thead>
        <tr>
            <th>no.</th>
            <th>id</th>
            <th>info</th>
            <th>longName</th>
            <th>shortName</th>
            <th>altitude</th>
            <th>gps</th>
            <th>distance</th>
            <th>lastHeard</th>
            <th>batteryLevel</th>
            <th>voltage</th>
            <th>chanUtil</th>
            <th>airUtilTx</th>
            <th>uptime</th>
            <th>hops</th>
        </tr>
    </thead>
    <tbody>\n";

    // Read all nodes from DB
    $nodes = $mysqli->query("SELECT * FROM Nodes")->fetch_all(MYSQLI_ASSOC);

    $a = 1;
    foreach($nodes as $node)
    {
        $node = json_decode($node['NodeData'], true);

        /////////////////////////
        /// UPTIME
        /////////////////////////

        // Translate $node['deviceMetrics']['uptimeSeconds'] into days, hours and minutes
        $uptime = $node['deviceMetrics']['uptimeSeconds'];
        $days = floor($uptime / 86400);
        $hours = floor(($uptime - $days * 86400) / 3600);
        $minutes = floor(($uptime - $days * 86400 - $hours * 3600) / 60);

        // Now combine those
        $uptime = "";
        $gotDay = 0;
        if($days > 0)
        {
            $uptime .= $days . "d";
            $gotDay = 1;
        }
        if($hours > 0)
        {
            $uptime .= "&nbsp;" . $hours . "h";
        }
        if($minutes > 0 AND $gotDay == 0)
        {
            $uptime .= "&nbsp;" . $minutes . "m";
        }

        if($uptime == "")
        {
            $uptime = "-";
        }

        /////////////////////////////////////////
        // Hide some data into on hover title
        /////////////////////////////////////////

        $info = "";
        $info .= "MAC address: " . $node['user']['macaddr'] . "\n";
        $info .= "HW model: " . $node['user']['hwModel'] . "\n";
        $info .= "Role: " . $node['user']['role'] . "\n";

        ///////////////////////////////////////////////////////
        // Create link to mapy.cz of coordinates are present
        ///////////////////////////////////////////////////////

        $gpsLink = "-";
        $gpsDistance = "-";
        if(is_numeric($node['position']['latitude']) && is_numeric($node['position']['longitude']))
        {
            $gpsLink = "<a href='https://mapy.cz/turisticka?q={$node['position']['latitude']}%20{$node['position']['longitude']}&y={$node['position']['latitude']}&x={$node['position']['longitude']}&z=13&ds=1' title='{$node['position']['latitude']} {$node['position']['longitude']}'>mapy.cz</a>";

            // Calculate GPS distance in meters based on coordinates from our position 50.4243603, 14.2455047
            $gpsDistance = round(calculateDistance($node['position']['latitude'], $node['position']['longitude'], 50.4243603, 14.2455047) / 1000, 1);
        }

        /////////////////////////////////////////////////
        // Translate lastHeard into how many hours ago
        /////////////////////////////////////////////////

        $lastHeard = "";
        $lastHeard = time() - $node['lastHeard'];

        $hoursTotal = floor($lastHeard / 3600);
        if($hoursTotal <= 4)
        {
            $classActive = 'byl-tu';
        }elseif($hoursTotal <= 24)
        {
            $classActive = 'neni-to-tak-davno';
        }
        else
        {
            $classActive = 'je-v-prdeli';
        }

        $days = floor($lastHeard / 86400);
        $hours = floor(($lastHeard - $days * 86400) / 3600);
        $minutes = floor(($lastHeard - $days * 86400 - $hours * 3600) / 60);

        // Now combine those
        $lastHeard = "";
        $gotDay = 0;
        if($days > 0)
        {
            $lastHeard .= $days . "d";
            $gotDay = 1;
        }
        if($hours > 0)
        {
            $lastHeard .= "&nbsp;" . $hours . "h";
        }
        if($minutes > 0 AND $gotDay == 0)
        {
            $lastHeard .= "&nbsp;" . $minutes . "m";
        }

        if($lastHeard == "")
        {
            $lastHeard = "-";
        }

        // Make statistics from $hoursTotal
        if($hoursTotal <= 1)
        {
            $nodeHistoryCountStats['1h']++;
        }elseif($hoursTotal <= 4)
        {
            $nodeHistoryCountStats['4h']++;
        }elseif($hoursTotal <= 8)
        {
            $nodeHistoryCountStats['8h']++;
        }elseif($hoursTotal <= 24)
        {
            $nodeHistoryCountStats['24h']++;
        }

        //////////////////////////////////
        // Extract number from altitude
        //////////////////////////////////

        $node['position']['altitude'] = preg_replace("/[^0-9]/", "", $node['position']['altitude']);

        //////////////////////////////////
        // Print row
        //////////////////////////////////

        $htmlPage .= "<tr class='$classActive'>\n";
        $htmlPage .= "<td align='right'>$a</td>\n";
        $htmlPage .= "<td>" . $node['user']['id'] . "</td>\n";
        $htmlPage .= "<td><div title='$info' class='infoButton'>?</div></td>\n";
        $htmlPage .= "<td>" . $node['user']['longName'] . "</td>\n";
        $htmlPage .= "<td>" . $node['user']['shortName'] . "</td>\n";
        $htmlPage .= "<td align='right' data-order='{$node['position']['altitude']}'>" . (is_numeric($node['position']['altitude']) ? $node['position']['altitude'] . " m" : "") . " </td>\n";
        $htmlPage .= "<td>$gpsLink</td>\n";
        $htmlPage .= "<td align='right' data-order='{$gpsDistance}'>" . $gpsDistance . " km</td>\n";
        $htmlPage .= "<td align='right' data-order='{$node['lastHeard']}'>" . $lastHeard . "</td>\n";
        $htmlPage .= "<td align='right'>" . (is_numeric($node['deviceMetrics']['batteryLevel']) ? $node['deviceMetrics']['batteryLevel'] : "-") . "</td>\n";
        $htmlPage .= "<td align='right'>" . (is_numeric($node['deviceMetrics']['voltage']) ? round($node['deviceMetrics']['voltage'], 2) : "-") . "</td>\n";
        $htmlPage .= "<td align='right'>" . round($node['deviceMetrics']['channelUtilization'], 1) . "%</td>\n";
        $htmlPage .= "<td align='right'>" . round($node['deviceMetrics']['airUtilTx'], 1) . "%</td>\n";
        $htmlPage .= "<td align='right' data-order='{$node['deviceMetrics']['uptimeSeconds']}'>" . $uptime . "</td>\n";
        $htmlPage .= "<td>" . (is_numeric($node['hopsAway']) ? $node['hopsAway'] : "-") . "</td>\n";
        $htmlPage .= "</tr>\n";

        $a++;
    }
    $htmlPage .= "</tbody>
    </table>";

    ////////////////////////////////////
    // Complete output for debug/info
    ////////////////////////////////////

    $htmlPage .= "<h2>Complete output from <code>meshtastic --info</code></h2>
    <button onclick='document.querySelector(\".completeOutput\").style.display = \"block\";this.style.display = \"none\";'>Show</button>
    <pre class='completeOutput'>";
    $htmlPage .= $data;
    $htmlPage .= "</pre>
    <p>&nbsp;</p>";

    ///////////////////////////////////////////////////
    // Make HTML output for $nodeHistoryCountStats
    ///////////////////////////////////////////////////

    $historyCountStatsHTML = "<span class='byl-tu'>{$nodeHistoryCountStats['1h']}</span> / <span class='byl-tu-min'>{$nodeHistoryCountStats['4h']}</span> / <span class=''>{$nodeHistoryCountStats['8h']}</span> / <span class='je-v-prdeli'>{$nodeHistoryCountStats['24h']}</span>&nbsp;&nbsp;&nbsp;<small>(1 h / 4 h / 8 h / 24 h)</small>";

    ////////////////////////////////////
    // Styling, Javascript, koniec
    ////////////////////////////////////

    $htmlPage .= "<style>
    body { background: #333; color: #ddd; font-family: Calibri, sans-serif; }
    a { color: #ddd; }
    
    table { background: #444; color: #ddd; margin-top: 10px; width: 500px !important; float: left; }
    tr:nth-child(odd) td { background: #666; padding: 5px !important; border-bottom: 1px solid #333; border-top: none; }
    tr:nth-child(even) td { background: #555; padding: 5px !important; border-bottom: 1px solid #333; border-top: none; }
    tr:hover td { background: #555; }
    
    .notSoBright { color: #888; }
    .infoButton { width: 18px; height: 18px; background: #888; color: #fff; text-align: center; cursor: pointer; }
    
    .completeOutput { display: none; }
    
    .dt-search { text-align: left; }
    
    .byl-tu, .byl-tu td, .byl-tu td a { color: lightgreen; }
    .byl-tu-min, .byl-tu-min td, .byl-tu-min td a { color: #b2eeaf; }
    .neni-to-tak-davno, .neni-to-tak-davno td, .neni-to-tak-davno td a { }
    .je-v-prdeli, .je-v-prdeli td, .je-v-prdeli td a { color: darkgray; }
    
    #nodeHistoryCountStats { background: #444; color: #ddd; padding: 5px; margin-bottom: 12px; width: 230px; text-align: center; font-size: 1rem; }
    </style>
    
    <!-- DataTables -->
    <link rel='stylesheet' href='https://cdn.datatables.net/2.0.7/css/dataTables.dataTables.min.css'>
    <script src=\"https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js\"></script>
    <script src='https://cdn.datatables.net/2.0.7/js/dataTables.min.js'></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Create DataTable
        $('#meshtable').DataTable({
            \"order\": [[ 0, \"asc\" ]],
            \"paging\": false,
            \"info\": false,
            \"searching\": true,
            type: 'html'
        });
    
        document.getElementById('nodeHistoryCountStats').innerHTML = \"$historyCountStatsHTML\";
    });
    </script>
    
    </body></html>";

    // Save
    file_put_contents(__DIR__ . "/nodes.html", $htmlPage);
}
else
{
    readfile(__DIR__ . "/nodes.html");
}