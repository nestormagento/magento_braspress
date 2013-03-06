<?php
/**
 * Módulo Braspress
 *
 * @category   Settor
 * @package    Settor_Braspress
 * @copyright  Copyright (c) 2012 Settor (http://www.settor.com.br)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 * @author     Paulo Pereira <paulo@settor.com.br>
 */
class Settor_Braspress_Model_Carrier_Method
	extends Mage_Shipping_Model_Carrier_Abstract
	implements Mage_Shipping_Model_Carrier_Interface
{
	protected $_code = 'settor_braspress';

	public $idOrigem 			= '2';
	public $meios 				= 'online';
	public $cnpjEmpresa 		= NULL;
	public $cepOrigem 			= NULL;
	public $cepDestino 			= NULL;
	public $documentoDestino 	= NULL;
	public $tipoFrete 			= NULL;
	public $peso 				= NULL;
	public $valor 				= NULL;
	public $volume 				= NULL;

    const URL_CALCULADOR = 'http://tracking.braspress.com.br/wscalculafreteisapi.dll/wsdl/IWSCalcFrete?wsdl';
 	
	public function collectRates(Mage_Shipping_Model_Rate_Request $request)
	{
		if( !$this->getConfigFlag('active') ) 
		{
			return FALSE;
		}

		$this->_init($request);

		$rates = $this->getRate();

		$result = Mage::getModel('shipping/rate_result');

		foreach( $rates as $r ){

			if( $r->MSGERRO == 'OK' )
			{
				$method_title = $r->TIPO;

				if( $this->getConfigData('prazo') )
				{
					$method_title .= ' - Prazo '.$r->PRAZOENTREGA.' dia(s)';
				}

				$method = Mage::getModel('shipping/rate_result_method');
				$method->setCarrier($this->_code);
				$method->setCarrierTitle($this->getConfigData('title'));

				$method->setMethod($this->_code);
				$method->setMethodTitle($method_title);

				$method->setPrice($r->TOTALFRETE);
				$method->setCost($r->TOTALFRETE);

				
				$result->append($method);
			}
			else
			{
				return FALSE;
			}

		}
 
		return $result;
	}

	public function _init($request)
	{
		$this->meios = $this->getConfigData('meios');
		$this->cnpjEmpresa = ereg_replace('[^0-9]','',$this->getConfigData('cnpj'));
		$this->ufOrigem = Mage::getStoreConfig('shipping/origin/region_id', $this->getStore());
		$this->cepOrigem = ereg_replace('[^0-9]','',Mage::getStoreConfig('shipping/origin/postcode', $this->getStore()));
		$this->cepDestino = ereg_replace('[^0-9]','',$request->getDestPostcode());
		$this->documentoDestino = $this->cnpjEmpresa;
		$this->tipoFrete = explode(',',$this->getConfigData('tipos'));
		$this->peso = $request->getPackageWeight();
		$this->valor = $request->getBaseCurrency()->convert($request->getPackageValue(),$request->getPackageCurrency());
		$this->volume = '1';
	}

	public function getRate()
	{
		if( $this->meios == 'online' )
		{
			try {
				$soap = @new SoapClient(self::URL_CALCULADOR);

				foreach( $this->tipoFrete as $tipo )
				{
					$r = $soap->CalculaFrete($this->cnpjEmpresa,$this->idOrigem,$this->cepOrigem,$this->cepDestino,$this->cnpjEmpresa,$this->documentoDestino,$tipo,$this->peso,$this->valor,$this->volume);
					$r->TIPO = ( $tipo == '1' )? 'Rodoviário' : 'Aéreo';
					$retorno[] = $r;
				}

			}
			catch(Exception $e)
			{
				Mage::log($e->getMessage(),NULL,'settor_braspress_'.date('Ymd').'.log');
				return FALSE;
			}		

		}
		else
		{	
			$retorno = FALSE;

			//$handle = fopen('./media/settor/braspress/'.$this->getConfigData('tabela'),'r');
			$handle = fopen($this->getConfigData('caminho').$this->getConfigData('tabela'),'r');

			if( !$handle )
			{
				return FALSE;
			}

			while( ($data = fgetcsv($handle,1000,';')) !== FALSE )
			{
				$r = array();

				$uf = $data[0];
				$cep_ini = $data[1];
				$cep_fim = $data[2];
				$peso_min = $data[3];
				$peso_max = $data[4];

				$fp = $data[5];
				$fv = $data[6];
				$ftm = $data[7];
				$gris = $data[8];
				$taxa_adm = $data[9];
				$tas = $data[10];
				$suframa = $data[11];
				$pedagio = $data[12];
				$tipo = $data[13];

				$regios_suframa = array('AM','RO','AC','RR','AP');

				if( $this->cepDestino >= $cep_ini && $this->cepDestino <= $cep_fim )
				{
					if( $this->peso >= $peso_min && $this->peso <= $peso_max )
					{
						$valor = $this->valor;

						$valor_1 = $fp;
						$valor_2 = $valor*($fv/100);
						$valor_3 = $pedagio;
						$valor_4 = $valor*($gris/100);
						$valor_5 = $valor_1+$valor_2+$valor_3+$valor_4;
						$valor_6 = $valor_5+($valor_5*($taxa_adm/100));

						$r['FP'] = $valor_1;
						$r['FV'] = $valor_2;
						$r['FTM'] = $ftm;
						$r['GRIS'] = $gris;
						$r['TAXA ADM'] = $taxa_adm;
						$r['TAS'] = $tas;
						$r['SUFRAMA'] = $suframa;
						$r['PEDAGIO'] = $pedagio;
						

						$subtotal = $valor_6;

						if( $this->ufOrigem != $this->_getRegionId($uf) )
						{
							$subtotal = $subtotal+$tas;

							if( in_array($uf,$regios_suframa) )
							{
								$subtotal = $subtotal+$suframa;
							}
						}

						$r['SUBTOTAL'] = $subtotal;

						$TOTALFRETE = $subtotal;

						if( ($ftm !== NULL || $ftm !== 0) &&  $ftm > $TOTALFRETE )
						{
							$TOTALFRETE = $ftm;

							if( $this->ufOrigem != $this->_getRegionId($uf) )
							{
								$TOTALFRETE = $TOTALFRETE+$tas;
							}
						}

						$r['TOTALFRETE'] = $TOTALFRETE;
						$r['TIPO'] = ( $tipo == 1 )? 'Rodoviário' : 'Aéreo';
						$r['PRAZOENTREGA'] = 1;
						$r['MSGERRO'] = 'OK';

						$retorno[] = (Object)$r;

					}
				}

			}

		}

		Mage::log('CALCULA FRETE',NULL,'settor_braspress_'.date('Ymd').'.log');
		Mage::log($this,NULL,'settor_braspress_'.date('Ymd').'.log');
		Mage::log($retorno,NULL,'settor_braspress_'.date('Ymd').'.log');
 
		return $retorno;
	}

	public function _getRegionId($uf)
	{
		$region['AC'] = 485;
		$region['AL'] = 486;
		$region['AP'] = 487;
		$region['AM'] = 488;
		$region['BA'] = 489;
		$region['CE'] = 490;
		$region['ES'] = 491;
		$region['GO'] = 492;
		$region['MA'] = 493;
		$region['MT'] = 494;
		$region['MS'] = 495;
		$region['MG'] = 496;
		$region['PA'] = 497;
		$region['PB'] = 498;
		$region['PR'] = 499;
		$region['PE'] = 500;
		$region['PI'] = 501;
		$region['RJ'] = 502;
		$region['RN'] = 503;
		$region['RS'] = 504;
		$region['RO'] = 505;
		$region['RR'] = 506;
		$region['SC'] = 507;
		$region['SP'] = 508;
		$region['SE'] = 509;
		$region['TO'] = 510;
		$region['DF'] = 511;

		return $region[$uf];
	}
 
	public function getAllowedMethods()
	{
		return array($this->_code=>$this->getConfigData('name'));
	}
 
}