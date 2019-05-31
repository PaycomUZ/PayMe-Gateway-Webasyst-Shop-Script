<?php

class paymePayment extends waPayment {

    private $pattern  = '/^(\w[\w\d]+)\.([^_]+)_(.+)$/';
	private $errorInfo ="";
	private $errorCod =0;

	private $request_id=0;
	private $responceType=0;

	private $result =true;
	private $inputArray;
	private $lastTransaction;

	public function payment($payment_form_data, $order_data, $auto_submit = false) {

		$order = waOrder::factory($order_data);

        if (!in_array($order->currency, $this->allowedCurrency())) {

            throw new waException('Unsupported currency');
        }

		$params = array(
            'merchant'    => $this->merchant_idd,
			'key'         => $this->merchant_password_for_test,
			'account[order_id]'  => sprintf('%s.%s_%s', $this->app_id, $this->merchant_id, $order->id),
            'amount'      => $order->total*100,
            'currency'    => $this->allowedCurrencyCod($order->currency),
            'description' => $order->description, 
            'server_url'  => $this->getRelayUrl(),
            'result_url'  => $this->getAdapter()->getBackUrl(waAppPayment::URL_SUCCESS, array('order_id' => $order->id)),
        );

		if ($this->test_mode) $form_url=$this->checkout_url_test; else $form_url=$this->checkout_url;

		$view = wa()->getView(); 
		$view->assign('hidden_fields', $params);
        $view->assign('form_url', $form_url);
        $view->assign('auto_submit', $auto_submit);
        return $view->fetch($this->path.'/templates/payment.html');
	}

	public function parseRequest() {

        $this->inputArray = json_decode(file_get_contents("php://input"), true);

		if ( (!isset($this->inputArray)) || empty($this->inputArray) ) { 

			$this->setErrorCod(-32700);

		} else {

			$parsingJsonError=false;

			switch (json_last_error()){

				case JSON_ERROR_NONE: break;
				default: $parsingJson=true; break;
			}

			if ($parsingJsonError) {

				$this->setErrorCod(-32700);

			} else {

				// Request ID
				if (!empty($this->inputArray['id']) ) {

					$this->request_id = filter_var($this->inputArray['id'], FILTER_SANITIZE_NUMBER_INT);
				}

					 if ($_SERVER['REQUEST_METHOD']!='POST') $this->setErrorCod(-32300);
				else if(! isset($_SERVER['PHP_AUTH_USER']))  $this->setErrorCod(-32504,"логин пустой");
				else if(! isset($_SERVER['PHP_AUTH_PW']))    $this->setErrorCod(-32504,"пароль пустой");
			}
		}
	}

	protected function callbackInit($request) {

		$this->parseRequest();

		if ($this->result) {

			if (!empty($this->inputArray['params']['account']['order_id']) ) {

				if (preg_match($this->pattern, $this->inputArray['params']['account']['order_id'], $match)) {

					$this->app_id      = $match[1];
					$this->merchant_id = $match[2];
					$this->order_id    = $match[3];
				}

			} else {
				
				$this->app_id = ifset($request['app_id']);
				$this->merchant_id = ifset($request['merchant_id']);
			}
		}
        return parent::callbackInit($request);
	}

	protected function callbackHandler($request) {

		if ($this->result) {

			$merchantKey="";

			// Chunki nastroykalar callbackInit da dostupno emas
			if ($this->test_mode) {

				$merchantKey=html_entity_decode($this->merchant_password_for_test );

			} else {

				$merchantKey=html_entity_decode($this->merchant_password );
			}

			if( $merchantKey != html_entity_decode($_SERVER['PHP_AUTH_PW']) ) {

				$this->setErrorCod(-32504,"неправильный  пароль");

			} else {

				if ( method_exists($this,"payme_".$this->inputArray['method'])) {

					$methodName="payme_".$this->inputArray['method'];
					$this->$methodName();

				} else {

					$this->setErrorCod(-32601, $this->inputArray['method'] );
				}
			}
		}
		$this->GenerateResponse();
		return array('template' => false);
	}

	public function payme_CheckPerformTransaction() {

		$transaction_array = $this->formalizeData($this->inputArray);

		// Поиск заказа по order_id
		$order = $this->getAdapter()->getOrderData($this->order_id, $this);

		// Заказ не найден
		if (! $order ) {

			$this->setErrorCod(-31050,'order_id');

		// Заказ найден
		} else {

			// Поиск транзакции по order_id
			$this->getLastTransactionForOrder($this->order_id, null);
 
			// Транзакция нет
			if (! $this->lastTransaction ) {

				// Проверка состояния заказа 
				if ($order['data']['paid_datetime'] ) {

					$this->setErrorCod(-31052, 'order_id');

				// Сверка суммы заказа 	
				} else  if ( ($order[total]*100) != $this->inputArray['params']['amount'] ) {

					$this->setErrorCod(-31001, 'order_id'); 

				// Allow true
				} else {

					$this->responceType=1;
				} 

			// Существует транзакция
			} else {

				$this->setErrorCod(-31051, 'order_id');
			}
		}
	}

	public function payme_CreateTransaction () {

		$transaction_data = $this->formalizeData($this->inputArray); 

		// Поиск заказа по order_id
		$order = $this->getAdapter()->getOrderData($this->order_id, $this);

		// Поиск транзакции по id
		$this->getLastTransactionForOrder($this->order_id, $this->inputArray['params']['id']);

		// Существует транзакция
		if ($this->lastTransaction) {

			$transactionInfo=json_decode( $this->lastTransaction['raw_data']['plugin_data'],true);

			$paycom_time_integer=(int)$transactionInfo['create_time'];
			$paycom_time_integer=$paycom_time_integer+43200000;

			// Проверка состояния заказа 
			if ($order['data']['paid_datetime'] ) {

				$this->setErrorCod(-31052, 'order_id');

			// Проверка состояния транзакции
			} else if ($this->lastTransaction['state']!=self::CALLBACK_CONFIRMATION){

				$this->setErrorCod(-31008, 'order_id');

			// Проверка времени создания транзакции	
			} else if ($paycom_time_integer <= $this->timestamp2milliseconds(time())){

				// Отменит reason = 4  CALLBACK_REFUND
				$transaction_array['order_id']    = $this->lastTransaction['order_id'];
				$transaction_array['state']       = self::CALLBACK_REFUND;
				$transaction_array['amount']	  = $this->lastTransaction['amount'];
				$transaction_array['currency_id'] = $this->lastTransaction['currency_id'];
				$transaction_array['native_id']	  = $this->lastTransaction['native_id'];
				$transaction_array['parent_id']   = $this->lastTransaction['id'];
				$transaction_array['parent_state']= $this->lastTransaction['state'];

				$transaction_data_model_array['plugin_data'] = json_encode(

					array(
				        'create_time'   => $transactionInfo['create_time'],
						'perform_time'  => $transactionInfo['perform_time'],
						'cancel_time'   => $this->timestamp2milliseconds(time()),
						'reason'        => 4,
						'state'         => -1
						 )
					);

				$this->saveTransaction($transaction_array, $transaction_data_model_array);
				$result = $this->execAppCallback(self::CALLBACK_REFUND, array('order_id'=>$this->lastTransaction['order_id']) );
				$this->getLastTransactionForOrder($this->lastTransaction['order_id'], $this->inputArray['params']['id']);

				$this->responceType=2;

			// Всё OK
			} else {

				$this->responceType=2;
			}
		
		// Транзакция нет
		} else {

			// Заказ не найден
			if (! $order ) {

				$this->setErrorCod(-31050,'order_id');

			// Заказ найден
			} else {

				// Проверка состояния заказа 
				if ($order['data']['paid_datetime'] ) {

					$this->setErrorCod(-31052, 'order_id');

				// Сверка суммы заказа 	
				} else  if ( ($order[total]*100) != $this->inputArray['params']['amount'] ) {

					$this->setErrorCod(-31001, 'order_id');

				// Запись транзакцию state=1 CALLBACK_CONFIRMATION
				} else {

					// Поиск транзакции  по order_id
					$this->getLastTransactionForOrder($this->order_id, null);

					// Транзакция нет
					if (! $this->lastTransaction ) {

						$transaction_array['order_id']    = $this->order_id;
						$transaction_array['state']       = self::CALLBACK_CONFIRMATION;
						$transaction_array['amount']	  = $this->inputArray['params']['amount'];
						$transaction_array['currency_id'] = $this->inputArray['params']['currency'];
						$transaction_array['native_id']	  = $this->inputArray['params']['id'];

						$transaction_data_model_array['plugin_data'] = json_encode(

							array(
								'create_time'   => $this->inputArray['params']['time'],
								'perform_time'  => 0,
								'cancel_time'   => 0,
								'reason'        => null,
								'state'         => 1
							)
						);

						$this->saveTransaction($transaction_array, $transaction_data_model_array);
						$result = $this->execAppCallback(self::CALLBACK_CONFIRMATION, array('order_id'=>$this->order_id ) );
						$this->responceType=2;
						$this->getLastTransactionForOrder($this->order_id, $this->inputArray['params']['id']);

					// Существует транзакция
					} else {

						$this->setErrorCod(-31051, 'order_id');
					}
				}
			}
		}
	}

	public function payme_CheckTransaction () {

		$transaction_data = $this->formalizeData($this->inputArray);

	    // Поиск транзакции по id
	    $this->getLastTransactionForOrder(null, $this->inputArray['params']['id']);

		// Существует транзакция
		if ($this->lastTransaction) {

			$this->responceType=2; 

		// Транзакция нет
		} else {

			$this->setErrorCod(-31003);
		}
	}

	public function payme_PerformTransaction() {

		$transaction_data = $this->formalizeData($this->inputArray);

		// Поиск транзакции по id
	    $this->getLastTransactionForOrder(null, $this->inputArray['params']['id']);

		// Существует транзакция
		if ( $this->lastTransaction ) {

			$transactionInfo=json_decode( $this->lastTransaction['raw_data']['plugin_data'],true );

			// Проверка состояние транзакцие CALLBACK_CONFIRMATION
			if ($this->lastTransaction['state']==self::CALLBACK_CONFIRMATION) {

				$paycom_time_integer=(int)$transactionInfo['create_time'];
				$paycom_time_integer=$paycom_time_integer+43200000;

				// Проверка времени создания транзакции	
				if( $paycom_time_integer <= $this->timestamp2milliseconds(time()) ) {

					// Отменит reason = 4  CALLBACK_REFUND
					$transaction_array['order_id']    = $this->lastTransaction['order_id'];
					$transaction_array['state']       = self::CALLBACK_REFUND;
					$transaction_array['amount']	  = $this->lastTransaction['amount'];
					$transaction_array['currency_id'] = $this->lastTransaction['currency_id'];
					$transaction_array['native_id']	  = $this->lastTransaction['native_id'];
					$transaction_array['parent_id']   = $this->lastTransaction['id'];
					$transaction_array['parent_state']= $this->lastTransaction['state'];

					$transaction_data_model_array['plugin_data'] = json_encode(

						array(
							'create_time'   => $transactionInfo['create_time'],
							'perform_time'  => $transactionInfo['perform_time'],
							'cancel_time'   => $this->timestamp2milliseconds(time()),
							'reason'        => 4,
							'state'         => -1
							 )
						);

					$this->saveTransaction($transaction_array, $transaction_data_model_array);
					$result = $this->execAppCallback(self::CALLBACK_REFUND, array('order_id'=>$this->lastTransaction['order_id']) );
					$this->getLastTransactionForOrder($this->lastTransaction['order_id'], $this->inputArray['params']['id']);

				// Всё Ok
				} else {

					$transaction_array['order_id']    = $this->lastTransaction['order_id'];
					$transaction_array['state']       = self::CALLBACK_PAYMENT;
					$transaction_array['amount']	  = $this->lastTransaction['amount'];
					$transaction_array['currency_id'] = $this->lastTransaction['currency_id'];
					$transaction_array['native_id']	  = $this->lastTransaction['native_id'];
					$transaction_array['parent_id']   = $this->lastTransaction['id'];
					$transaction_array['parent_state']= $this->lastTransaction['state'];

					$transaction_data_model_array['plugin_data'] = json_encode(

						array(
				            'create_time'   => $transactionInfo['create_time'],
							'perform_time'  => $this->timestamp2milliseconds(time()),
							'cancel_time'   => $transactionInfo['cancel_time'],
							'reason'        => $transactionInfo['reason'],
							'state'         => 2
							 )
					);

					$this->saveTransaction($transaction_array, $transaction_data_model_array);
					$result = $this->execAppCallback(self::CALLBACK_PAYMENT, array('order_id'=>$this->lastTransaction['order_id']) );
					$this->getLastTransactionForOrder($this->lastTransaction['order_id'], $this->inputArray['params']['id']);
				}

				$this->responceType=2;

			// Cостояние не 1 CALLBACK_CONFIRMATION
			} else {

				// Проверка состояние транзакцие
				if ($this->lastTransaction['state']==self::CALLBACK_PAYMENT) {

					$this->responceType=2;

				// Cостояние не 2 CALLBACK_PAYMENT
				} else {

					$this->setErrorCod(-31008);
				}
			}

		// Транзакция нет
		} else {

			$this->setErrorCod(-31003);
		}
	}

	public function payme_CancelTransaction() {

		$transaction_data = $this->formalizeData($this->inputArray);

	    // Поиск транзакции по id
	    $this->getLastTransactionForOrder(null, $this->inputArray['params']['id']);

	    // Существует транзакция
		if ($this->lastTransaction) {

			$transactionInfo=json_decode( $this->lastTransaction['raw_data']['plugin_data'],true);

			// Проверка состояние транзакцие CALLBACK_CONFIRMATION
			if ($this->lastTransaction['state']==self::CALLBACK_CONFIRMATION) {

				// Отменит reason = inputArray  CALLBACK_REFUND
				$transaction_array['order_id']    = $this->lastTransaction['order_id'];
				$transaction_array['state']       = self::CALLBACK_REFUND;
				$transaction_array['amount']	  = $this->lastTransaction['amount'];
				$transaction_array['currency_id'] = $this->lastTransaction['currency_id'];
				$transaction_array['native_id']	  = $this->lastTransaction['native_id'];
				$transaction_array['parent_id']   = $this->lastTransaction['id'];
				$transaction_array['parent_state']= $this->lastTransaction['state'];

				$transaction_data_model_array['plugin_data'] = json_encode(

					array(
				        'create_time'   => $transactionInfo['create_time'],
						'perform_time'  => $transactionInfo['perform_time'],
						'cancel_time'   => $this->timestamp2milliseconds(time()),
						'reason'        => $this->inputArray['params']['reason'],
						'state'         => -1
						 )
					);

				$this->saveTransaction($transaction_array, $transaction_data_model_array);
				$result = $this->execAppCallback(self::CALLBACK_REFUND, array('order_id'=>$this->lastTransaction['order_id']));
				$this->getLastTransactionForOrder($this->lastTransaction['order_id'], $this->inputArray['params']['id']);

			// Cостояние 2 CALLBACK_PAYMENT
			} else if ($this->lastTransaction['state']==self::CALLBACK_PAYMENT) {

				// Отменит reason = inputArray  CALLBACK_REFUND
				$transaction_array['order_id']    = $this->lastTransaction['order_id'];
				$transaction_array['state']       = self::CALLBACK_REFUND;
				$transaction_array['amount']	  = $this->lastTransaction['amount'];
				$transaction_array['currency_id'] = $this->lastTransaction['currency_id'];
				$transaction_array['native_id']	  = $this->lastTransaction['native_id'];
				$transaction_array['parent_id']   = $this->lastTransaction['id'];
				$transaction_array['parent_state']= $this->lastTransaction['state'];

				$transaction_data_model_array['plugin_data'] = json_encode(

					array(
				        'create_time'   => $transactionInfo['create_time'],
						'perform_time'  => $transactionInfo['perform_time'],
						'cancel_time'   => $this->timestamp2milliseconds(time()),
						'reason'        => $this->inputArray['params']['reason'],
						'state'         => -2
						 )
					);

				$this->saveTransaction($transaction_array, $transaction_data_model_array);
				$result = $this->execAppCallback(self::CALLBACK_REFUND, array('order_id'=>$this->lastTransaction['order_id']));
				$this->getLastTransactionForOrder($this->lastTransaction['order_id'], $this->inputArray['params']['id']);

			// Cостояние CALLBACK_REFUND
			} else {

				// Ничего не надо делать
			}

			$this->responceType=2;

		// Транзакция нет
		} else {

			$this->setErrorCod(-31003);
		}
	}

	public function payme_ChangePassword() {

		$this->saveSettings(
		array(
		
			'merchant_idd'              =>$this->merchant_idd,
			'merchant_password'         =>$this->inputArray['params']['password'],
			'merchant_password_for_test'=>$this->merchant_password_for_test,
			'test_mode'                 =>$this->test_mode,
			'checkout_url'              =>$this->checkout_url,
			'checkout_url_test'         =>$this->checkout_url_test,
			'product_information'       =>$this->product_information,
			'return_after_payment'      =>$this->return_after_payment,
			'payment_language'          =>$this->payment_language
		));

		$this->responceType=3;
	}
 
	public function payme_GetStatement() {
 
		$tm = new waTransactionModel();

		$sql = "SELECT * FROM {$tm->getTableName()} WHERE plugin = ? AND app_id = ? AND merchant_id = ? ";

		$this->parent_transaction = $tm->query($sql, $this->id, $this->app_id, $this->merchant_id)->fetchAssoc();
	}

	protected function getLastTransactionForOrder($order_id, $native_id) {

		$search=array(
			'plugin'      => $this->id,
			'app_id' 	  => $this->app_id,
			'merchant_id' => $this->merchant_id
		);

		if ($order_id)  $search['order_id'] =$order_id;
		if ($native_id) $search['native_id']=$native_id;

		$transactions = $this->getTransactionsByFields($search);

        if ($transactions) {

            foreach ($transactions as $id => $transaction) {

                $child_transaction_count=0;

				foreach ($transactions as $id_1 => $transaction_1) {

					if ($transaction['id'] == $transaction_1['parent_id']) {

						$child_transaction_count=$child_transaction_count+1;
					}
				}

				if ($child_transaction_count==0) {
					$this->lastTransaction=$transaction;
				}
            }
        }
    }

	public function GenerateResponse() {

		if ($this->errorCod==0) {

			if ($this->responceType==1) {

				$responseArray = array('result'=>array( 'allow' => true )); 

			} else if ($this->responceType==2) {

				$transactionInfo=json_decode( $this->lastTransaction['raw_data']['plugin_data'],true );

				$responseArray = array(); 
			    $responseArray['id']     = $this->request_id;
				$responseArray['result'] = array(

					"create_time"	=> (int)$transactionInfo['create_time'],
					"perform_time"  => (int)$transactionInfo['perform_time'],
					"cancel_time"   => (int)$transactionInfo['cancel_time'],
					"transaction"	=>  $this->lastTransaction['order_id'], //$this->order_id,
					"state"			=> (int)$transactionInfo['state'],
					"reason"		=> (is_null($transactionInfo['reason'])?null:(int)$transactionInfo['reason'])
				);
			} else if ($this->responceType==3) {

				$responseArray = array('result'=>array( 'success' => true )); 
			}

		} else {

			$responseArray['id']    = $this->request_id;
			$responseArray['error'] = array (

				'code'   =>(int)$this->errorCod,
				'message'=> array(

					"ru"=>$this->getGenerateErrorText($this->errorCod,"ru"),
					"uz"=>$this->getGenerateErrorText($this->errorCod,"uz"),
					"en"=>$this->getGenerateErrorText($this->errorCod,"en"),
					"data" =>$this->errorInfo
			));
		}

		wa()->getResponse()->addHeader('Content-type', 'application/json; charset=UTF-8;');
		wa()->getResponse()->sendHeaders();

		echo json_encode($responseArray);
	}

	public function setErrorCod($cod_,$info=null) {

		$this->errorCod=$cod_;

		if ($info!=null) $this->errorInfo=$info;

		if ($cod_!=0)  {

			$this->result=false;
		}
	}

	public function allowedCurrency() {

        return array( 'RUB', 'UZS', 'USD', 'EUR');
    }

	public function allowedCurrencyCod($currency_code) {

             if( $currency_code == 'UZS') return 860;
		else if( $currency_code == 'USD') return 840;
		else if( $currency_code == 'RUB') return 643;
		else if( $currency_code == 'EUR') return 978;
		else							  return 860;
    }

	public function getGenerateErrorText($codeOfError,$codOfLang ){

		$listOfError=array ('-31001' => array(
		                                  "ru"=>'Неверная сумма.',
						                  "uz"=>'Неверная сумма.',
							              "en"=>'Неверная сумма.'
										),
							'-31003' => array(
		                                  "ru"=>'Транзакция не найдена.',
						                  "uz"=>'Транзакция не найдена.',
							              "en"=>'Транзакция не найдена.'
										),
							'-31008' => array(
		                                  "ru"=>'Невозможно выполнить операцию.',
						                  "uz"=>'Невозможно выполнить операцию.',
							              "en"=>'Невозможно выполнить операцию.'
										),
							'-31050' => array(
		                                  "ru"=>'Заказ не найден.',
						                  "uz"=>'Заказ не найден.',
							              "en"=>'Заказ не найден.'
										),
							'-31051' => array(
		                                  "ru"=>'Существует транзакция.',
						                  "uz"=>'Существует транзакция.',
							              "en"=>'Существует транзакция.'
										),

							'-31052' => array(
											"ru"=>'Заказ уже оплачен.',
											"uz"=>'Заказ уже оплачен.',
											"en"=>'Заказ уже оплачен.'
										),
										
							'-32300' => array(
		                                  "ru"=>'Ошибка возникает если метод запроса не POST.',
						                  "uz"=>'Ошибка возникает если метод запроса не POST.',
							              "en"=>'Ошибка возникает если метод запроса не POST.'
										),
							'-32600' => array(
		                                  "ru"=>'Отсутствуют обязательные поля в RPC-запросе или тип полей не соответствует спецификации',
						                  "uz"=>'Отсутствуют обязательные поля в RPC-запросе или тип полей не соответствует спецификации',
							              "en"=>'Отсутствуют обязательные поля в RPC-запросе или тип полей не соответствует спецификации'
										),
							'-32700' => array(
		                                  "ru"=>'Ошибка парсинга JSON.',
						                  "uz"=>'Ошибка парсинга JSON.',
							              "en"=>'Ошибка парсинга JSON.'
										),
							'-32600' => array(
		                                  "ru"=>'Отсутствуют обязательные поля в RPC-запросе или тип полей не соответствует спецификации.',
						                  "uz"=>'Отсутствуют обязательные поля в RPC-запросе или тип полей не соответствует спецификации.',
							              "en"=>'Отсутствуют обязательные поля в RPC-запросе или тип полей не соответствует спецификации.'
										),
							'-32601' => array(
		                                  "ru"=>'Запрашиваемый метод не найден. В RPC-запросе имя запрашиваемого метода содержится в поле data.',
						                  "uz"=>'Запрашиваемый метод не найден. В RPC-запросе имя запрашиваемого метода содержится в поле data.',
							              "en"=>'Запрашиваемый метод не найден. В RPC-запросе имя запрашиваемого метода содержится в поле data.'
										),
							'-32504' => array(
		                                  "ru"=>'Недостаточно привилегий для выполнения метода.',
						                  "uz"=>'Недостаточно привилегий для выполнения метода.',
							              "en"=>'Недостаточно привилегий для выполнения метода.'
										),
							'-32400' => array(
		                                  "ru"=>'Системная (внутренняя ошибка). Ошибку следует использовать в случае системных сбоев: отказа базы данных, отказа файловой системы, неопределенного поведения и т.д.',
						                  "uz"=>'Системная (внутренняя ошибка). Ошибку следует использовать в случае системных сбоев: отказа базы данных, отказа файловой системы, неопределенного поведения и т.д.',
							              "en"=>'Системная (внутренняя ошибка). Ошибку следует использовать в случае системных сбоев: отказа базы данных, отказа файловой системы, неопределенного поведения и т.д.'
										)
						    );

		return $listOfError[$codeOfError][$codOfLang];
	}

	public function timestamp2milliseconds($timestamp) {
        // is it already as milliseconds
        if (strlen((string)$timestamp) == 13) {
            return $timestamp;
        }

        return $timestamp * 1000;
    }
	
	public function datetime2timestamp($datetime) {

        if ($datetime) {

            return strtotime($datetime);
        }

        return $datetime;
    }

}
