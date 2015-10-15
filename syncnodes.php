<?php

	/*
		Communities, die keine eigene Domäne haben – also Teil einer Domäne sind,
		können sich nicht richtig an der Freifunk-API anmelden, da die zugehörigen Nodes
		erst aus dem großen Ganzen herausgefiltert werden müssen.
		
		Das kann mit diesem Script geschehen!
		
		ACHTUNG das hier ist Quick&Dirty gemacht. Nichts ist großartig abgefangen und wurde von einem PHP-Noob geschrieben.
		 
		Konfiguriert ihr was falsch, hagelt es Fehler. :)
		
		Das Script holt sich die nodes.json vom Map-Server der Domäne,
		filtert dann, ob sich der einzelne Node in z.B. Gemeinde-Grenzen befindet. (GEOjson Polygon von http://global.mapit.mysociety.org/)
		
		Hat der Knoten keine Koordinaten, kann er mit Hilfe eines Prefix im Nodename auch noch ins "Töpfchen" kommen.
		
		Wenn alle Sticke reißen, kann man noch einzelne Nodes white- oder blacklisten.
		Das ist gerade dann interessant, wenn die Grenzen nicht eindeutig eingehalten werden können,
		was in der Realität sich hin und wieder vorkommt.
		
		Damit's auch mit der Freifunk-API klappt, gibt es im Unterverzeichnis ein API-Template-File (syncnode_apifile.tpl).
		Das bitte entsprechend anpassen. Wichtig ist, dass es zwei Platzhalter gibt, die dann mit den passenden Werten ersetzt wird.
		
		##nodecount## -> Aktuelle Anzahl von Nodes in der Community
		##lastchange## -> Aktuelle Uhrzeit
		
		Das Script ist für die Belange in der Domäne Möhne und dessen SubDomains geeignet.		
	*/
	




	// Source (Die nodes.json der gesamten (Sub-)Domain)
	define("NODES_URL","http://map.freifunk-moehne.de/data-meschedebestwig/nodes.json");
	
	// Target (Wohin die gefilterte json geschrieben werden soll.)
	define("OUTPUT_PATH",$_SERVER['DOCUMENT_ROOT']); //Achtung das Root-Verzeichnis kann je nach Webserver anders interpretiert werden! Legt es hin, wohin ihr wollt.
	define("TARGET_FILENAME","nodes.json");
	
	// In die Datei wird die Source gespiegelt. Zum Beispiel um in einer eigenen Karte alle Nodes der Domäne anzuzeigen. Wenn man es nicht braucht: Auskommentiert lassen. 
	// define("ALLNODES_TARGET_FILENAME","allnodes.json");
	
	// Pfad zu API-File-Template. ##nodecount## wird mit der Anzahl der Nodes ersetzt und ##lastchange## mit dem aktuellen Zeitstempel
	define("APIFILE_TEMPLATE","api/syncnode_apifile.tpl");
	
	// Pfad wohin das gefüllte API-File geschrieben werden soll.
	define("APIFILE","api/freifunk-api.json");
	
	// Pfad zum GEOjson-File (Shapefile/Polygon) Funktioniert mit den .geojson-Dateien der Stadt/Gemeinde-Grenzen 
	// Ich habe mir gespart hier eine komplette GEOjson-Library einzubauen. 
	define("GEOJSON","api/geo/bestwig.geojson");
	
	// Dieser Prefix wird immer dann ausgewertet, wenn ein Knoten keine Koordinaten hat. Wenn dieser Knoten aber ein passendes Prefix hat, wird er nicht ausgefiltert.
	$filter_str = "ff-bestwig";
	
	// Blacklist NodeIDs (Knoten, die aus der nodes.json fliegen sollen)
	$blacklist = array("14cc2030c366", "14cc2030c35a", "14cc2030c344");
	
	// Whitelist NodeIDs (Knoten, die mit in die nodes.json aufgenommen werden sollen)
	$whitelist = array("");
	
	
	
	
	
	
	/* 
		*******************
        *  CONFIG ENDE :) *
		*******************	
	*/
	
	
	
	
	
	$time_start = microtime_float();
	
	//Error Reporting aktivieren
	error_reporting(E_ALL);
	
	$polyX = array();
	$polyY = array();
	
	getGEOjson($polyX,$polyY);
	


	
	// Anzahl der Ecken des Polygons ermitteln. 
	$polySides = count($polyX)-1;





	//Stream Objekt erstellen
	$fHandle = fopen(NODES_URL,"r");
	
	$buffer = '';
	if($fHandle) {
		while (!feof($fHandle)) {
			$buffer .= fgetss($fHandle, 5000);
			}	
		
		//ALLNODES schreiben
		if(defined('ALLNODES_TARGET_FILENAME'))
			file_put_contents(OUTPUT_PATH."/".ALLNODES_TARGET_FILENAME,$buffer);			
		
		
		$data = json_decode($buffer,true);
		
		//Stream Resourcen freigeben
		fclose($fHandle);	
	}
	
	
	
	
	//Anzahl aller Nodes
	$nodecount = count($data['nodes']);
		
	foreach($data['nodes'] as $node_element => $node) {	
	
		$nodename = strtolower($node['nodeinfo']['hostname']);
		
		// Wenn der Knoten in der Blacklist steht
		if(in_array($node_element, $blacklist)) {
			unset($data['nodes'][$node_element]);
			$nodecount--;
		} 		
		
		// Wenn der Knoten in der Whiteliste steht
		if(in_array($node_element, $whitelist)) {
			continue;
		} 		
		
		
		// Wenn ein Knoten Koordinaten hat
		if(array_key_exists("location", $node['nodeinfo']) && array_key_exists("latitude", $node['nodeinfo']['location']) && array_key_exists("longitude", $node['nodeinfo']['location']))
		{
			$x = $node['nodeinfo']['location']['latitude'];
			$y = $node['nodeinfo']['location']['longitude'];
			
			// Wenn sich der Knoten innerhalb des Polygons befindet.
			if (!pointInPolygon($polySides,$polyX,$polyY,$x,$y)) {
				unset($data['nodes'][$node_element]);
				$nodecount--;
			}
		} else if(strpos($nodename, $filter_str) === false ) {
				unset($data['nodes'][$node_element]);
				$nodecount--;
		}	
	}
	
	
	
	
	updateApiFile($nodecount);
	




	$new_json = json_encode($data);
	
	file_put_contents(OUTPUT_PATH."/".TARGET_FILENAME,$new_json);
	
	$time_end = microtime_float();
	$time = $time_end - $time_start;
	
	echo "Laufzeit: ". $time. " Sekunden";
	
	
	




	
	
	
function pointInPolygon($polySides,$polyX,$polyY,$x,$y) {
  $j = $polySides-1 ;
  $oddNodes = 0;
  for ($i=0; $i<$polySides; $i++) {
    if ($polyY[$i]<$y && $polyY[$j]>=$y  ||  $polyY[$j]<$y && $polyY[$i]>=$y) {
        if ($polyX[$i]+($y-$polyY[$i])/($polyY[$j]-$polyY[$i])*($polyX[$j]-$polyX[$i])<$x)  {
            $oddNodes=!$oddNodes; 
        }
    }
   $j=$i; }

  return $oddNodes;
}







function updateApiFile($nodecount) {
	$fHandle = fopen(APIFILE_TEMPLATE,"r");
	$buffer = '';
	if($fHandle) {
		while (!feof($fHandle)) {
			$buffer .= fgetss($fHandle, 5000);
		}	
		
		$apifile = str_replace("##nodecount##", $nodecount, $buffer);
		$apifile = str_replace("##lastchange##", gmdate("Y-m-d\TH:i:s\Z"), $apifile);
		
		file_put_contents(OUTPUT_PATH."/".APIFILE,$apifile);
		
		fclose($fHandle);	
	}


}





function microtime_float()
{
    list($usec, $sec) = explode(" ", microtime());
    return ((float)$usec + (float)$sec);
}






function getGEOjson(&$x,&$y)
{
	$fHandle = fopen(GEOJSON,"r");
	
	$buffer = '';
	if($fHandle) {
		while (!feof($fHandle)) {
			$buffer .= fgetss($fHandle, 5000);
			}	
		$data = json_decode($buffer,true);
		
		fclose($fHandle);	
		
		foreach($data['coordinates'][0] as $test1) {				
			array_push($y, $test1[0]);
			array_push($x, $test1[1]);
		}			
	}
}

	

?>