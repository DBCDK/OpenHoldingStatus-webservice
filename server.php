<?php
/**
 *
 * This file is part of Open Library System.
 * Copyright © 2009, Dansk Bibliotekscenter a/s,
 * Tempovej 7-11, DK-2750 Ballerup, Denmark. CVR: 15149043
 *
 * Open Library System is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Open Library System is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Open Library System.  If not, see <http://www.gnu.org/licenses/>.
*/


require_once('OLS_class_lib/webServiceServer_class.php');
require_once('OLS_class_lib/oci_class.php');
require_once('OLS_class_lib/z3950_class.php');

class openHoldings extends webServiceServer {
 
  protected $tracking_id;
  protected $curl;
  protected $dom;

  public function __construct() {
    webServiceServer::__construct('openholdingstatus.ini');
    $this->curl = new curl();
    $this->curl->set_option(CURLOPT_TIMEOUT, 30);
    $this->dom = new DomDocument();
    $this->dom->preserveWhiteSpace = false;
  }

 /* \brief
  * request:
  * - agencyId: Identifier of agency
  * - pid: Identifier of Open Search object
  * - mergePids: merge localisations for all pids
  * response:
  * - localisations
  * - - pid: Identifier of Open Search object
  * - - errorMessage 
  * - or 
  * - - agencyId: Identifier of agency
  * - - note: Note from local library
  * - - codes: string
  * - - localIdentifier: string
  * - error
  * - - pid: Identifier of Open Search object
  * - - responderId: librarycode for lookup-library
  * - - errorMessage 
  * 
  * @param $param object - the request
  * @retval object - the answer
  */
  public function localisations($param) {
    $this->tracking_id = verbose::set_tracking_id('ohs', $param->trackingId->_value);
    $lr = &$ret->localisationsResponse->_value;
    if (!$this->aaa->has_right('netpunkt.dk', 500)) {
      $error = 'authentication_error';
    } 
    else {
      is_array($param->pid) ? $pids = $param->pid : $pids[] = $param->pid;
      $sort_n_merge = (self::xs_boolean($param->mergePids->_value) && count($pids) > 1);
      if ($sort_n_merge) {
        $url = sprintf($this->config->get_value('agency_request_order','setup'), 
                       self::strip_agency($param->agencyId->_value));
        $res = $this->curl->get($url);
        $curl_status = $this->curl->get_status();
        if ($curl_status['http_code'] == 200) {
          if ($this->dom->loadXML($res)) {
            foreach ($this->dom->getElementsByTagName('agencyId') as $aid)
              $r_order[$aid->nodeValue] = count($r_order);
          }
          else {
            $error = 'cannot_parse_request_order';
          }
        }
        else {
          $error = 'error_fetching_request_order';
          verbose::log(ERROR, 'OpenHoldings:: fetch request order http code: ' . $curl_status['http_code'] .
                              ' error: "' . $curl_status['error'] .
                              '" for: ' . $curl_status['url']);
        }
      }
    }

    if ($error) {
      $lr->error->_value->responderId->_value = $param->agencyId->_value;
      $lr->error->_value->errorMessage->_value = $error;
      return $ret;
    }

// if more than one pid, this could be parallelized
    foreach ($pids as $pid) {
      unset($error);
      $url = sprintf($this->config->get_value('ols_get_holdings','setup'), 
                     self::strip_agency($param->agencyId->_value), 
                     urlencode($pid->_value));
      $res = $this->curl->get($url);
      $curl_status = $this->curl->get_status();
      if ($curl_status['http_code'] == 200) {
        if ($this->dom->loadXML($res)) {
// <holding fedoraPid="870970-basis:28542941">
//   <agencyId>715700</agencyId>
//   <note></note>
//   <codes></codes>
// </holding>
          if ($holdings = $this->dom->getElementsByTagName('holding')) {
            foreach ($holdings as $holding) {
              foreach ($holding->childNodes as $node) {
                $hold[$node->localName] = $node->nodeValue;
              }
              $hold['fedoraPid'] = $holding->getAttribute('fedoraPid');
              if ($sort_n_merge) {
                if (!isset($r_order[ $hold['agencyId'] ]))
                  $r_order[ $hold['agencyId'] ] = count($r_order) + 1000;
                $hold['sort'] = sprintf('%06s', $r_order[ $hold['agencyId'] ]);
              }
              $pid_hold[] = $hold;
              unset($hold);
            }
            if ($sort_n_merge) {
              $h_arr[0]['pids'][] = $pid->_value;
              if (empty($h_arr[0]['holds'])) {
                $h_arr[0]['holds'] = array();
              }
              if (is_array($pid_hold)) {
                $h_arr[0]['holds'] = array_merge($h_arr[0]['holds'], $pid_hold);
              }
            }
            else {
              $h_arr[] = array('pids' => array($pid->_value), 'holds' => $pid_hold);
            }
            unset($pid_hold);
          }
        }
        else {
          $error = 'error_parsing_holdings_server_answer';
        }
      }
      else {
        $error = 'error_contacting_holdings_server';
        verbose::log(ERROR, 'OpenHoldings:: http code: ' . $curl_status['http_code'] .
                            ' error: "' . $curl_status['error'] .
                            '" for: ' . $curl_status['url']);
      }
      if ($error) {
        $err->pid[]->_value = $pid->_value;
        $err->errorMessage->_value = $error;
        $lr->localisations[]->_value = $err;
        unset($err);
      }
    }

    if (empty($h_arr) && $error) {
      unset($lr->localisations);
      $lr->error->_value->responderId->_value = $param->agencyId->_value;
      $lr->error->_value->errorMessage->_value = $error;
      return $ret;
    }

    if ($sort_n_merge && is_array($h_arr)) {
      usort($h_arr[0]['holds'], array($this, 'compare'));
    }

//print_r($h_arr); die();
    if (is_array($h_arr)) {
      foreach ($h_arr as $holds) {
        foreach ($holds['pids'] as $pid)
          $one_pid->pid[]->_value = $pid;
        if (isset($holds['holds'])) {
          foreach ($holds['holds'] as $hold) {
            $agency->localisationPid ->_value = $hold['fedoraPid'];
            $agency->agencyId->_value = $hold['agencyId'];
            if ($hold['note']) $agency->note->_value = $hold['note'];
            if ($hold['codes']) $agency->codes->_value = $hold['codes'];
            if ($hold['callNumber']) $agency->callNumber->_value = $hold['callNumber'];
            if ($hold['localIdentifier']) $agency->localIdentifier->_value = $hold['localIdentifier'];
            $one_pid->agency[]->_value = $agency;
            unset($agency);
          }
        }
        $lr->localisations[]->_value = $one_pid;
        unset($one_pid);
      }
    }

    return $ret;
  }


 /* \brief
  * request:
  * - lookupRecord
  * - - responderId: librarycode for lookup-library
  * - - pid
  * - or next 
  * - - bibliographicRecordId: requester record id 
  * response:
  * - responder
  * - - localHoldingsId
  * - - note:
  * - - willLend;
  * - - expectedDelivery;
  * - - pid
  * - or next
  * - - bibliographicRecordId: requester record id 
  * - - responderId: librarycode for lookup-library
  * - error
  * - - pid
  * - or next
  * - - bibliographicRecordId: requester record id 
  * - - responderId: librarycode for lookup-library
  * - - errorMessage: 
  *
  * @param $param object - the request
  * @retval object - the answer
  */
  public function holdings($param) {
    $this->tracking_id = verbose::set_tracking_id('ohs', $param->trackingId->_value);
    $hr = &$ret->holdingsResponse->_value;
    if (!$this->aaa->has_right('netpunkt.dk', 500))
      $auth_error = 'authentication_error';
    if (isset($param->lookupRecord)) {
      // force to array
      if (!is_array($param->lookupRecord)) {
        $help = $param->lookupRecord;
        unset($param->lookupRecord);
        $param->lookupRecord[] = $help;
      }
      foreach ($param->lookupRecord as $holding) {
        if (!$fh = $auth_error)
          $fh = self::find_holding($holding->_value);
        if (is_scalar($fh)) {
          self::add_recid($err, $holding);
          $err->responderId->_value = $holding->_value->responderId->_value;
          $err->errorMessage->_value = $fh;
          $hr->error[]->_value = $err;
          unset($err);
        } else {
          self::add_recid($fh, $holding);
          $fh->responderId->_value = $holding->_value->responderId->_value;
          $hr->responder[]->_value = $fh;
        }
      }
    }

    return $ret;
  }

 /* \brief
  * request:
  * - lookupRecord
  * - - responderId: librarycode for lookup-library
  * - - pid
  * - or next 
  * - - bibliographicRecordId: requester record id 
  * response:
  * - error
  * - - pid
  * - or next
  * - - bibliographicRecordId: requester record id 
  * - - responderId: librarycode for lookup-library
  * - - errorMessage: 
  *
  * @param $param object - the request
  * @retval object - the answer
  */
  public function detailedHoldings($param) {
    $this->tracking_id = verbose::set_tracking_id('ohs', $param->trackingId->_value);
    $dhr = &$ret->detailedHoldingsResponse->_value;
    if (!$this->aaa->has_right('netpunkt.dk', 500)) {
      $dhr->error->_value->errorMessage->_value = 'authentication_error';
    }
    elseif (isset($param->lookupRecord)) {
      if (!is_array($param->lookupRecord)) {
        $help = $param->lookupRecord;
        unset($param->lookupRecord);
        $param->lookupRecord[] = $help;
      }
      foreach ($param->lookupRecord as $holding) {
        if (!$fh = $auth_error) {
          $fh = self::find_holding($holding->_value, TRUE);
        }
//var_dump($holding);
//var_dump($fh);
        self::add_recid($dh, $holding);
        $dh->responderId->_value = $holding->_value->responderId->_value;
        if (is_scalar($fh)) {
          $dh->errorMessage->_value = $fh;
        } else {
          foreach ($fh as $fhi) {
            foreach (array('id' => 'localItemId', 'policy' => 'policy', 'date' => 'expectedDelivery', 'fee' => 'fee', 'note' => 'note', 'item' => 'itemText', 'target_location_id' => 'localIdentifier', 'level-0' => 'enumLevel0', 'level-1' => 'enumLevel1', 'level-2' => 'enumLevel2', 'level-3' => 'enumLevel3') as $key => $val) {
              if ($help = $fhi[$key]) {
                $item->$val->_value = ($key == 'date' ? substr($help, 0, 10) : trim($help));
              }
            }
//var_dump($fhi); var_dump($item);
            $dh->holdingsItem[]->_value = $item;
            unset($item);
          }
        }
        $dhr->responderDetailed[]->_value = $dh;
        unset($dh);
      }
//var_dump($ret); die();
    }

    return $ret;
  }

  /* -------------------------------------------------------------------------------- */

  /** \brief 
   *
   * @param $obj object
   * @param $hold object
   */
  private function add_recid(&$obj, &$hold) {
    if (isset($hold->_value->pid)) {
      $obj->pid->_value = $hold->_value->pid->_value;
    }
    else {
      $obj->bibliographicRecordId->_value = $hold->_value->bibliographicRecordId->_value;
    }
  }

  /** \brief 
   *
   * @param $param object
   * @param $detailed boolean
   * @retval mixed
   */
  private function find_holding($param, $detailed = FALSE) {
    $connect_info = self::find_protocol_and_address($param->responderId->_value);
    switch ($connect_info['protocol']) {
      case 'z3950':
        return self::find_z3950_holding($connect_info, $param, $detailed);
        break;
      case 'iso20775':
        return self::find_iso20775_holding($connect_info, $param, $detailed);
        break;
      default:
        return 'service_not_supported_by_library';
    }
  }

 /* /brief
  * struct lookupRecord {
  *   string responderId;
  *   string pid;
  * - or next
  *   string bibliographicRecordId;
  *  }
  *
  * @param $z_info array
  * @param $param object
   * @param $detailed boolean
  * @retval mixed
  */
  private function find_z3950_holding($z_info, $param, $detailed) {
    static $z3950;
    if (empty($z3950)) {
      $z3950 = new z3950();
    }
    list($target, $database) = explode('/', $z_info['url'], 2);
    $z3950->set_target($target);
    $z3950->set_database($database);
    $z3950->set_authentication($z_info['authentication']);
    $z3950->set_syntax('xml');
    $z3950->set_element('B3');
    $z3950->set_schema('1.2.840.10003.13.7.4');
    $z3950->set_start(1);
    $z3950->set_step(1);
    if (isset($param->pid)) {
      list($bibpart, $recid) = explode(':', $param->pid->_value);
    }
    else {
      $recid = $param->bibliographicRecordId->_value;
    }
    $z3950->set_rpn('@attr 4=103 @attr BIB1 1=12 ' . $recid);
    $this->watch->start('z3950');
    $hits = $z3950->z3950_search(5);
    $this->watch->stop('z3950');
    if ($z3950->get_error()) {
      verbose::log(ERROR, 'OpenHoldings:: ' . $z_info['url'] . ' Z3950 error: ' . $z3950->get_error_string());
      return 'error_searching_library';
    }
    if (!$hits) {
      verbose::log(TRACE, 'OpenHoldings:: z3950: ' . $z_info['url'] . ' id: ' . $recid . ' item_not_found');
      return 'item_not_found';
    }
    $record = $z3950->z3950_record(1);
    verbose::log(TRACE, 'OpenHoldings:: z3950: ' . $z_info['url'] . ' id: ' . $recid . ' record: ' . str_replace("\n", '', $record));
    if (empty($record)) {
      return 'no_holding_return_from_library';
    }
    if ($status = self::parse_z3950_holding($record)) {
      return $detailed ? $status : self::parse_status($status);
    }
    else {
      return 'cannot_parse_library_answer';
    }
  }

 /*
  * struct lookupRecord {
  *   string responderId;
  *   string pid;
  * - or next
  *   string bibliographicRecordId;
  *  }
  *
  * @param $info array
  * @param $param object
  * @param $detailed boolean
  * @retval mixed
  */
  private function find_iso20775_holding($info, $param, $detailed) {
    $rega_url = $this->config->get_value('iso20775_server','setup');
    if (isset($param->pid)) {
      list($bibpart, $recid) = explode(':', $param->pid->_value);
    }
    else {
      $recid = $param->bibliographicRecordId->_value;
    }
    $post->trackingId = $this->tracking_id;
    $post->timeout = 10000;
    $post->records[0]->baseUrl = $info['url'];
    $post->records[0]->recordId = $recid;
    $this->curl->set_post(json_encode($post), 0);
    $this->curl->set_option(CURLOPT_HTTPHEADER, array('Content-Type: application/json; charset=utf-8'), 0);
    if ($this->config->get_value('use_test_iso20775_replies','setup')) {
      $result[0]->record->holding = self::test_iso20775_reply($recid, self::strip_agency($param->responderId->_value));
      $result[0]->responseCode = 200;
      $curl_status['http_code'] = 200;
    }
    else {
      $this->watch->start('iso20775');
      $result = @ json_decode($this->curl->get($rega_url, 0));
      $curl_status = $this->curl->get_status();
      $this->watch->stop('iso20775');
    }
//var_dump(json_encode($post)); var_dump($result); var_dump($curl_status); die();
    if ($curl_status['http_code'] == 200) {
      if ($result[0]->responseCode == 200) {
        if ($result[0]->holding) {
          verbose::log(TRACE, 'OpenHoldings:: iso20775: ' . $info['url'] . 
                              ' id: ' . $recid . ' record: ' . str_replace("\n", '', $result[0]->holding));
          if ($status = self::parse_iso20775_holding($result[0]->holding)) {
            return $detailed ? $status : self::parse_status($status);
            //return $status;
          }
          else {
            verbose::log(ERROR, 'OpenHoldings:: Cannot parse: "' . $result[0]->holding .
                                '" from: ' . $info['url']);
            return 'cannot_parse_library_answer';
          }
        }
        else {
          verbose::log(TRACE, 'OpenHoldings:: External responseCode: 200 ' . 
                              ' errorMsg: "' . $result[0]->errorMsg .
                              ' holding: "' . $result[0]->holding .
                              '" for: ' . $info['url']);
          return 'item_not_found';
        }
      }
      else {
        verbose::log(ERROR, 'OpenHoldings:: External responseCode: ' . $result[0]->responseCode .
                            ' errorMsg: "' . $result[0]->errorMsg .
                            ' holding: "' . $result[0]->holding .
                            '" for: ' . $info['url']);
        return 'error_searching_library';
      }
    } 
    else {
      verbose::log(FATAL, 'OpenHoldings:: http error: ' . $curl_status['http_code'] .
                          ' error: "' . $curl_status['error'] .
                          '" for: ' . $curl_status['url']);
      return 'error_searching_library';
    }

  }

  /** \brief Parse a holding record and extract the status
   *
   * @param $holding string - xml document
   * @retval mixed
   */
  private function parse_iso20775_holding($holding) {
    if ($this->dom->loadXML($holding)) {
      foreach ($this->dom->getElementsByTagName('holding') as $item) {
        if (!$h['fee'] = self::first_node_value($item, 'summaryPolicy/feeInformation/feeText')) {
          $h['fee'] = self::prefix('Reason: ', self::first_node_value($item, 'summaryPolicy/feeInformation/feeStructured/feeReason'));
          $h['fee'] .= self::prefix('Unit: ', self::first_node_value($item, 'summaryPolicy/feeInformation/feeStructured/feeUnit'));
          $h['fee'] .= self::prefix('Amount: ', self::first_node_value($item, 'summaryPolicy/feeInformation/feeStructured/feeAmount'));
        }
        $h['note'] = self::first_node_value($item, 'summaryPolicy/availability/text');
        if ($item->getElementsByTagName('holdingSimple')->length) {
          $h['id'] = self::first_node_value($this->dom, 'resource/resourceIdentifier/value');
          if (self::first_node_value($item, 'holdingSimple/copiesSummary/status/availableCount')) {
            $h['policy'] = 1;
            $h['date'] = self::first_node_value($item, 'holdingSimple/copiesSummary/status/earliestDispatchDate');
          }
          else {
            $h['policy'] = 0;
          }
          $hold[] = $h;
        }
        else {
          foreach ($item->getElementsByTagName('holdingStructured')->item(0)->getElementsByTagName('set') as $hs) {
            foreach ($hs->getElementsByTagName('component') as $cpn) {
              $h['id'] = self::first_node_value($cpn, 'pieceIdentifier/value');
              $h['level-0'] = 
              $h['item'] = self::first_node_value($cpn, 'enumerationAndChronology/text');
              $h['date'] = self::first_node_value($cpn, 'availabilityInformation/status/dateTimeAvailable');
              $h['policy'] = self::first_node_value($cpn, 'availabilityInformation/status/availabilityStatus');
              $hold[] = $h;
            }
          }
        }
      }
    }
    else {
      return FALSE;
    }
    //var_dump($holding); var_dump($hold); die();
    return $hold;
  }

  /** \brief Parse a holding record and extract the status
   *
   * @param $holding string - xml document
   * @retval mixed
   */
  private function parse_z3950_holding($holding) {
    if ($this->dom->loadXML($holding)) {
      //echo str_replace('?', '', $holding);
/*
         <bibView-11 targetBibPartId-40="09267999ZXZX1991/2006ZX0000ZXZX" numberOfPieces-56="1" >
            <bibPartLendingInfo-116 servicePolicy-109="1" >
            </bibPartLendingInfo-116>
            <bibPartEnumeration-45 enumLevel-93="1" enumCaption-94="År: " specificEnumeration-95="0000" >
               <ChildEnumeration enumLevel-93="2" enumCaption-94="Volume: " specificEnumeration-95="1991/2006" >
               </ChildEnumeration>
            </bibPartEnumeration-45>
         </bibView-11>
*/
      $target_location_id = $this->dom->getElementsByTagName('holdingsSiteLocation-6')->item(0)->getAttribute('targetLocationId-26');
      foreach ($this->dom->getElementsByTagName('bibView-11') as $item) {
        $h = array();
        foreach ($item->attributes as $key => $attr)
          if ($key == 'targetBibPartId-40')
            $h['id'] = $attr->nodeValue;
        foreach ($item->getElementsByTagName('bibPartLendingInfo-116') as $info) {
          foreach ($info->attributes as $key => $attr)
            switch ($key) {
              case 'servicePolicy-109' : 
                $h['policy'] = $attr->nodeValue;
                break;
              case 'servicefee-110' : 
                $h['fee'] = 'fee'; // $attr->nodeValue; 2do in seperate tag??
                break;
              case 'expectedDispatchDate-111' : 
                $h['date'] = $attr->nodeValue;
                break;
              case 'serviceNotes-112' : 
                $h['note'] = $attr->nodeValue;
                break;
            }
        }
        $h['target_location_id'] = $target_location_id;
        foreach ($item->getElementsByTagName('bibPartEnumeration-45') as $info) { 
          self::get_enumeration($info, $h);
        }
        foreach ($item->getElementsByTagName('bibPartChronology-46') as $info) {
          self::get_chronology($info, $h);
        }

        $hold[] = $h;
      }
      if (empty($hold)) {
        return array(array('note' => 'No holding'));
      }
      else {
        return $hold;
      }
    }
    else {
      return FALSE;
    }
  }

  /** \brief parse attributes of bibpartchronology-46
   *
   *  <bibpartchronology-46 chronLevel-96="1" chroncaption-97="År: " specificchronology-98="0000" >
   *  </bibpartchronology-46
   *
   * @param $node DOMNode
   * @param $list array 
   */
  private function get_chronology($node, &$list) {
    if ($node) {
      foreach ($node->attributes as $key => $attr) {
        switch ($key) {
          case 'chronLevel-96':
            $level = $attr->nodeValue;
            break;
          case 'chroncaption-97':
            $caption = $attr->nodeValue;
            break;
          case 'specificchronology-98':
            $enum = $attr->nodeValue;
            break;
        }
      }
      $list['level-0'] = $enum;
      $list['item'] .= $caption . $enum . ' ';
    }
  }

  /** \brief parse attributes of bibPartEnumeration-45 and ChildEnumeration
   *
   *  <bibPartEnumeration-45 enumLevel-93="1" enumCaption-94="År: " specificEnumeration-95="0000" >
   *    <ChildEnumeration enumLevel-93="2" enumCaption-94="Volume: " specificEnumeration-95="1991/2006" >
   *    </ChildEnumeration>
   *  </bibPartEnumeration-45>
   *
   * @param $node DOMNode
   * @param $list array 
   */
  private function get_enumeration($node, &$list) {
    if ($node) {
      foreach ($node->attributes as $key => $attr) {
        switch ($key) {
          case 'enumLevel-93':
            $level = $attr->nodeValue;
            break;
          case 'enumCaption-94':
            $caption = $attr->nodeValue;
            break;
          case 'specificEnumeration-95':
            $enum = $attr->nodeValue;
            break;
        }
      }
      $list['level-' . $level] = $enum;
      $list['item'] .= trim($caption) . $enum . ' ';
      self::get_enumeration($node->getElementsByTagName('ChildEnumeration')->item(0), $list); 
    }
  }

  /** \brief Parse status for availability
   *
   * @param $status array
   * @retval mixed
   */
  private function parse_status($status) {
    if (count($status) == 1 && $status[0]['policy']) {
      $s = &$status[0];
      $ret->localHoldingsId->_value = $s['id'];
      if ($s['note'])
        $ret->note->_value = $s['note'];
      $h_date = substr($s['date'],0,10);
      if ($s['policy'] == 1) {
        $ret->willLend->_value = 'true';
        if ($h_date >= date('Y-m-d'))
          $ret->expectedDelivery->_value = $h_date;
      } elseif (($s['policy'] == 2))
          $ret->willLend->_value = 'false';
    } elseif (count($status) > 1) {
      $ret->note->_value = 'check_local_library';
      $ret->willLend->_value = 'true';
      $pol = 0;
      foreach ($status as $s)
        if ($s['policy'] <> 1) {
          $ret->willLend->_value = 'false';
          break ;
        }
    } else 
      $ret = 'no_holdings_specified_by_library';

    return $ret;
  }

  /** \brief Get the protocol and address for a library from openAgency
   *
   * @param $lib string - library number
   * @retval mixed
   */
  private function find_protocol_and_address($lib) {
    static $z_infos = array();
    if (empty($z_infos[$lib])) {
      $url = sprintf($this->config->get_value('agency_server_information','setup'), 
                     self::strip_agency($lib));
      $res = $this->curl->get($url);
      $curl_status = $this->curl->get_status();
      if ($curl_status['http_code'] == 200) {
        if ($this->dom->loadXML($res)) {
          $z_infos[$lib]['url'] = self::first_node_value($this->dom, 'address');
          switch ($z_infos[$lib]['protocol'] = self::first_node_value($this->dom, 'protocol')) {
            case 'iso20775':
              $z_infos[$lib]['password'] = self::first_node_value($this->dom, 'passWord');
              break;
            case 'z3950':
              $auth = self::first_node_value($this->dom, 'userId') . '/' .
                      self::first_node_value($this->dom, 'groupId') . '/' .
                      self::first_node_value($this->dom, 'passWord');
              if ($auth <> '//') {
                $z_infos[$lib]['authentication'] = $auth;
              }
              break;
            default:
          }
        }
        else {
          verbose::log(ERROR, 'OpenHoldings:: Cannot parse serverInformation url ' . $url);
          return FALSE;
        }
      }
      else {
        verbose::log(ERROR, 'OpenHoldings:: fetch serverInformation http code: ' . $curl_status['http_code'] .
                            ' error: "' . $curl_status['error'] .
                            '" for: ' . $curl_status['url']);
        return FALSE;
      }
    }
    return $z_infos[$lib];
  }

  /** \brief Return first nodeValue from a dom node
   * 
   * @param $domnode DOMNode
   * @param $path string - path to node like 'tagA/tagB/tagC'
   * @retval mixed - string or FALSE
   */
  private function first_node_value($dom_node, $path) {
    $tags = explode('/', $path);
    foreach ($tags as $tag) {
      $dom_list = $dom_node->getElementsByTagName($tag);
      if ($dom_list->length) {
        $dom_node = $dom_list->item(0);
      }
      else {
        return NULL;
      }
    }
    return $dom_node->nodeValue;
  }

  /** \brief Puts $prefix and '. ' around value if set
   *
   * @param $prefix string
   * @param $value string
   * @retval string
   */
  private function prefix($prefix, $value) {
    return ($value ? $prefix . $value . '. ': $value);
  }

  /** \brief
   * @param $id string - library number or ISIL library id
   * @retval string - return only digits, so something like DK-710100 returns 710100
   */
  private function strip_agency($id) {
    return preg_replace('/\D/', '', $id);
  }

  /** \brief return true if xs:boolean is so
   * @param $str string
   * @retval boolean 
   */
  private function xs_boolean($str) {
    return (strtolower($str) == 'true' || $str == 1);
  }

  /** \brief Helper function for sorting
   * @param $a array
   * @param $b array
   * @retval boolean 
   */
  private function compare($a, $b) {
    return $a['sort'] > $b['sort'];
  }

  /** \brief Helper function for test when no iso20775 host exist
   * @param $id string
   * @param $lib string
   * @retval string - iso20775 xml reply 
   */
  private function test_iso20775_reply($id, $lib) {
    require_once('examples.php');
    return test_iso_reply($id, $lib);
  }
}

/*
 * MAIN
 */

$ws=new openHoldings();
$ws->handle_request();


