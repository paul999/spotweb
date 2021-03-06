<?php
require_once "SpotRetriever_Abs.php";

class SpotRetriever_Spots extends SpotRetriever_Abs {
		private $_rsakeys;
		private $_outputType;
		private $_retrieveFull;

		/**
		 * server - de server waar naar geconnect moet worden
		 * db - database object
		 * rsakeys = array van rsa keys
		 */
		function __construct($server, $db, $rsakeys, $outputType, $retrieveFull) {
			parent::__construct($server, $db);
			
			$this->_rsakeys = $rsakeys;
			$this->_outputType = $outputType;
			$this->_retrieveFull = $retrieveFull;
		} # ctor


		/*
		 * Geef de status weer in category/text formaat. Beide zijn vrij te bepalen
		 */
		function displayStatus($cat, $txt) {
			if ($this->_outputType != 'xml') {
				switch($cat) {
					case 'start'			: echo "Retrieving new Spots from server...\r\n"; break;
					case 'done'				: echo "Finished retrieving spots.\r\n\r\n"; break;
					case 'dbcount'			: echo "Spots in database:	" . $txt . "\r\n"; break;
					case 'groupmessagecount': echo "Appr. Message count: 	" . $txt . "\r\n"; break;
					case 'firstmsg'			: echo "First message number:	" . $txt . "\r\n"; break;
					case 'lastmsg'			: echo "Last message number:	" . $txt . "\r\n"; break;
					case 'curmsg'			: echo "Current message:	" . $txt . "\r\n"; break;
					case 'progress'			: echo "Retrieving " . $txt; break;
					case 'hdrparsed'		: echo " (parsed " . $txt . " headers, "; break;
					case 'fullretrieved'	: echo "retrieved " . $txt . " full spots, "; break;
					case 'verified'			: echo "verified " . $txt . ", of "; break;
					case 'loopcount'		: echo $txt . " spots)\r\n"; break;
					case 'totalprocessed'	: echo "Processed a total of " . $txt . " spots\r\n"; break;
					case ''					: echo "\r\n"; break;
					
					default					: echo $cat . $txt;
				} # switch
			} else {
			
				switch($cat) {
					case 'start'			: echo "<spots>"; break;
					case 'done'				: echo "</spots>"; break;
					case 'dbcount'			: echo "<dbcount>" . $txt . "</dbcount>"; break;
					case 'totalprocessed'	: echo "<totalprocessed>" . $txt . "</totalprocessed>"; break;
					default					: break;
				} # switch
			} # else xmloutput
		} # displayStatus
		
		/*
		 * De daadwerkelijke processing van de headers
		 */
		function process($hdrList, $curMsg, $increment) {
			$this->displayStatus("progress", ($curMsg) . " till " . ($curMsg + $increment));
		
			$this->_db->beginTransaction();
			$signedCount = 0;
			$hdrsRetrieved = 0;
			$fullsRetrieved = 0;
			
			# pak onze lijst met messageid's, en kijk welke er al in de database zitten
			$t = microtime(true);
			$dbIdList = $this->_db->matchMessageIds($hdrList);
			
			# en loop door elke header heen
			foreach($hdrList as $msgid => $msgheader) {
				# Reset timelimit
				set_time_limit(120);

				# messageid to check
				$msgId = substr($msgheader['Message-ID'], 1, -1);

				# als we de spot overview nog niet in de database hebben, haal hem dan op
				if (!in_array($msgId, $dbIdList['spot'])) {
					$hdrsRetrieved++;
					
					$spotParser = new SpotParser();
					$spot = $spotParser->parseXover($msgheader['Subject'], 
													$msgheader['From'], 
													$msgheader['Message-ID'],
													$this->_rsakeys);
					if ($spot['Verified']) {
						$this->_db->addSpot($spot);
						$dbIdList['spot'][] = $msgId;
						
						if ($spot['WasSigned']) {
							$signedCount++;
						} # if
					} # if
				} # if

				# We willen enkel de volledige spot ophalen als de header in de database zit, omdat 
				# we dat hierboven eventueel doen, is het enkel daarop checken voldoende
				if ((in_array($msgId, $dbIdList['spot'])) &&   # header moet in db zitten
				   (!in_array($msgId, $dbIdList['fullspot']))) # maar de fullspot niet
				   {
					#
					# We gebruiken altijd XOVER, dit is namelijk handig omdat eventueel ontbrekende
					# artikel nummers (en soms zijn dat er duizenden) niet hoeven op te vragen, nu
					# vragen we enkel de de headers op van de artikelen die er daadwerkelijk zijn
					#
					if ($this->_retrieveFull) {
						$fullSpot = array();
						try {
							$fullsRetrieved++;
							$fullSpot = $this->_spotnntp->getFullSpot(substr($msgheader['Message-ID'], 1, -1));

							# en voeg hem aan de database toe
							$this->_db->addFullSpot($fullSpot);
						} 
						catch(ParseSpotXmlException $x) {
							; # swallow error
						} 
						catch(Exception $x) {
							# messed up index aan de kant van de server ofzo? iig, dit gebeurt. soms, if so,
							# swallow the error
							if ($x->getMessage() == 'No such article found') {
								;
							} else {
								throw $x;
							} # else
						} # catch
						
					} # if retrievefull
				} # if fullspot is not in db yet
			} # foreach

			if (count($hdrList) > 0) {
				$this->displayStatus("hdrparsed", $hdrsRetrieved);
				$this->displayStatus("fullretrieved", $fullsRetrieved);
				$this->displayStatus("verified", $signedCount);
				$this->displayStatus("loopcount", count($hdrList));
			} else {
				$this->displayStatus("hdrparsed", 0);
				$this->displayStatus("fullretrieved", 0);
				$this->displayStatus("verified", 0);
				$this->displayStatus("loopcount", 0);
			} # else

			$this->_db->setMaxArticleid($this->_server['host'], $curMsg);
			$this->_db->commitTransaction();				
			
			return count($hdrList);
		} # process()
	
} # class SpotRetriever_Spots