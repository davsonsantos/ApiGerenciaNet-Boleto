<?php

/**
 * Boleto [ MODEL ]
 * Modelo responsável pela Emissão e Cancelamento de boletos, Consultadas de Saldo e Solicitação de Tranferencia de valores da plataforma https://gerencianet.com.br!
 * 
 */
require_once __DIR__ . '/../Library/GerenciaNet/api/vendor/autoload.php';

use Gerencianet\Exception\GerencianetException;
use Gerencianet\Gerencianet;

class ApiGerenciaNet {

    var $Gerencianet;
    var $ClientId; //ID DE IDENTIFICAÇÃO DO GERENCIANET
    var $ClientSecret; //CODE SECRET GERENCIANET
    var $Options;
    var $Body;
    var $Data;
    var $Result;
    var $Error;

    function __construct() {

        $this->ClientId = PAYMENT_GERENCIA_NET_CLIENTID;
        $this->ClientSecret = PAYMENT_GERENCIA_NET_CLIENTSECRET;
        $this->Options = [
            'client_id' => $this->ClientId,
            'client_secret' => $this->ClientSecret,
            'sandbox' => (PAYMENT_ENV == "sandbox" ? true : false) // altere conforme o ambiente (true = desenvolvimento e false = produção)
        ];
    }

    /**
     * 
     * @param array $Dados - Informações so Clinete
     * @param array $Item - Item que serão cobradas no boleto
     * @return type - Retorna os dados do boleto
     */
    public function emitiBoleto(array $Dados, array $Item) {
        $this->Data = $Dados;
        $this->Clear($Dados);
        if ($Item['repeats'] == 1):
            $this->montaItensBoleto($Item);
            $this->exeBoleto();
        else:
            $this->montaItensCarne($Item);
            $this->exeCarne();
        endif;
        return $this->Result;
    }

    /**
     * <b>Itens do Carner</b> Validação para emissão do boleto</b>
     * @param array $Item
     */
    private function montaItensCarne($Item) {

        $item_1 = [
            'name' => $Item['description'],
            'amount' => (int) $Item['amount'],
            'value' => (int) str_replace('.', '', $Item['value']),
        ];

        $items = [$item_1];
        $metada = [
            'notification_url' => $Item['notification']
        ];
        $customer = ['name' => $this->Data['name'], 'cpf' => $this->Data['document'], 'phone_number' => $this->Data['phone']]; //dados obrigatorios do cliente
        $this->Body = [
            'items' => $items,
            'customer' => $customer,
            'expire_at' => date('Y-m-d', strtotime($this->Data['due_date'])),
            'repeats' => (int) $Item['repeats'],
            'split_items' => false,
            'metadata' => $metada,
        ];
    }
    
    /**
     * <b>Itens do Boleto</b> Validação para emissão do boleto</b>
     * @param array $Item
     */
    private function montaItensBoleto($Item) {
        $item_1 = [
            'name' => $Item['description'],
            'amount' => (int) $Item['amount'],
            'value' => (int) str_replace('.', '', $Item['value'])
        ];        
        $items = [$item_1];
        $metada = [
            'notification_url' => $Item['notification']
        ];
        $this->Body = ['items' => $items, 'metadata' => $metada];
    }

    private function exeCarne() {
        try {
            $api = new Gerencianet($this->Options);
            $this->Result = $api->createCarnet([], $this->Body);
        } catch (GerencianetException $e) {
            $this->Result = ['ErrorCode' => 'Code Error: ' . $e->code, 'Erro' => 'Error: ' . $e->error, 'ErroDescription' => 'Error Description: ' . $e->errorDescription]; // retorno os erros da plataform            
        } catch (Exception $e) {
            $this->Result = ['ErroMessage' => 'Message Exeption: ' . $e->getMessage()]; //retorno o erros de excessão
        }
    }
    
    private function exeBoleto() {
        try {
            $api = new Gerencianet($this->Options);
            $charge = $api->createCharge([], $this->Body);

            if ($charge["code"] == 200):
                $params = ['id' => $charge["data"]["charge_id"]]; 
                $customer = ['name' => $this->Data['name'],'cpf' => $this->Data['document'],'phone_number' => $this->Data['phone']]; //dados obrigatorios do cliente
                $bankingBillet = ['expire_at' => date('Y-m-d', strtotime($this->Data['due_date'])),'customer' => $customer]; //Paramento de vencimento do boleto
                $payment = ['banking_billet' => $bankingBillet];
                $this->Body = ['payment' => $payment];
                $api = new Gerencianet($this->Options);
                $this->Result = $api->payCharge($params, $this->Body);
            endif;
        } catch (GerencianetException $e) {
            $this->Result = ['ErrorCode' => 'Code Error: ' . $e->code, 'Erro' => 'Error: ' . $e->error, 'ErroDescription' => 'Error Description: ' . $e->errorDescription]; // retorno os erros da plataform
            return $this->Result;
        } catch (Exception $e) {
            $this->Result = ['ErroMessage' => 'Message Exeption: ' . $e->getMessage()]; //retorno o erros de excessão
            return $this->Result;
        }
    }

    /**
     * <b>getNotification</b> Responsavel por receber as notificações da plataforma
     * @Param $TokenNoficication
     * @return Array Dados da transação
     */
    public function getNotification($TokenNoficication) {
        $params = ['token' => $TokenNoficication];
        try {
            $api = new Gerencianet($this->Options);
            $chargeNotification = $api->getNotification($params, []);
            $i = count($chargeNotification["data"]);
            $ultimoStatus = $chargeNotification["data"][$i - 1];
            $status = $ultimoStatus["status"];
            $this->Result = ['charge_id' => $ultimoStatus["identifiers"]["charge_id"], 'statusAtual' => $status["current"]];
            return $this->Result;
        } catch (GerencianetException $e) {
            $this->Result = ['ErrorCode' => 'Code Error: ' . $e->code, 'Erro' => 'Error: ' . $e->error, 'ErroDescription' => 'Error Description: ' . $e->errorDescription];
            return $this->Result;
        } catch (Exception $e) {
            $this->Result = ['ErroMessage' => 'Message Exeption: ' . $e->getMessage()];
            return $this->Result;
        }
    }

    /**
     * <b>getNotification</b> Responsavel por receber as notificações da plataforma
     * @Param $Charge_id
     * @return Array Dados da transação
     */
    public function getCancellation($Charge_id) {
        $params = ['id' => $Charge_id]; //charge_id do boleto
        try {
            $api = new Gerencianet($this->Options);
            $charge = $api->cancelCharge($params, []);
            $this->Result = $charge;
            return $this->Result;
        } catch (GerencianetException $e) {
            $this->Result = ['ErrorCode' => 'Code Error: ' . $e->code, 'Erro' => 'Error: ' . $e->error, 'ErroDescription' => 'Error Description: ' . $e->errorDescription];
            return $this->Result;
        } catch (Exception $e) {
            $this->Result = ['ErroMessage' => 'Message Exeption: ' . $e->getMessage()];
            return $this->Result;
        }
    }

    /**
     * <b>Obter Resultado:</b> Retorna um array associativo com o resultado da classe.
     * @return ARRAY $Result = Array associatico com o erro
     */
    public function getResult() {
        echo $this->Result;
    }

    //Limpa código e espaços!
    private function Clear() {
        array_map('strip_tags', $this->Data);
        array_map('trim', $this->Data);
    }

}
