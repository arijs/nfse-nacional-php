<?php declare(strict_types=1);

namespace Arijs\NfseNacional\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Arijs\NfseNacional\Ambiente;
use Arijs\NfseNacional\Excecao\SefinExcecao;
use Arijs\NfseNacional\Sefin\SefinNacional;
use Arijs\NfseNacional\Tests\Support\ClienteHttpFalso;
use Arijs\NfseNacional\Util\Gzip;

final class SefinNacionalTest extends TestCase
{
	private const CHAVE = '35503082123456780001950000000000012026110000000017';

	public function testEnviarDpsCompactaEmGzipBase64EInterpretaSucesso(): void
	{
		$nfseXml = '<?xml version="1.0" encoding="UTF-8"?><NFSe xmlns="http://www.sped.fazenda.gov.br/nfse"><infNFSe><nNFSe>17</nNFSe></infNFSe></NFSe>';
		$http = (new ClienteHttpFalso())->responder(201, [
			'tipoAmbiente' => 2,
			'versaoAplicativo' => 'SefinNacional_1.6',
			'dataHoraProcessamento' => '2026-11-06T13:15:00-03:00',
			'idDps' => 'DPS355030821234567800019500001000000000000042',
			'chaveAcesso' => self::CHAVE,
			'nfseXmlGZipB64' => Gzip::comprimirBase64($nfseXml),
			'alertas' => [['codigo' => 'A0001', 'descricao' => 'aviso qualquer']],
		]);
		$sefin = new SefinNacional(Ambiente::Homologacao, $http);

		$r = $sefin->enviarDps('<DPS>assinada</DPS>');

		self::assertTrue($r->sucesso());
		self::assertSame(self::CHAVE, $r->chaveAcesso());
		self::assertSame('17', $r->numeroNfse());
		self::assertSame($nfseXml, $r->nfseXml());
		self::assertCount(1, $r->alertas());

		$chamada = $http->chamadas[0];
		self::assertSame('POST', $chamada['metodo']);
		self::assertSame('https://sefin.producaorestrita.nfse.gov.br/SefinNacional/nfse', $chamada['url']);
		self::assertSame('application/json', $chamada['cabecalhos']['Content-Type']);
		self::assertSame('<DPS>assinada</DPS>', Gzip::descomprimirBase64($http->ultimoJson()['dpsXmlGZipB64']));
	}

	public function testRejeicaoViraListaDeErrosEExcecao(): void
	{
		$http = (new ClienteHttpFalso())->responder(400, [
			'tipoAmbiente' => 2,
			'erros' => [
				['codigo' => 'E0006', 'descricao' => 'Ambiente informado diverge do ambiente de recebimento', 'complemento' => 'tpAmb=1'],
				['codigo' => 'E1235', 'descricao' => 'Falha no esquema XML do DF-e.'],
			],
		]);
		$r = (new SefinNacional(Ambiente::Producao, $http))->enviarDps('<DPS/>');

		self::assertFalse($r->sucesso());
		self::assertSame(['E0006', 'E1235'], array_map(static fn($e) => $e->codigo, $r->erros()));
		self::assertStringContainsString('tpAmb=1', (string) $r->erros()[0]);

		try {
			$r->exigirSucesso();
			self::fail('Deveria lançar.');
		} catch (SefinExcecao $e) {
			self::assertSame(400, $e->getCode());
			self::assertStringContainsString('E0006', $e->getMessage());
			self::assertSame($r, $e->resposta);
		}
	}

	public function testHttpSemJsonTambemEErro(): void
	{
		$http = (new ClienteHttpFalso())->responder(403, '<html>Forbidden</html>');
		$r = (new SefinNacional(Ambiente::Producao, $http))->consultarNfse(self::CHAVE);

		self::assertFalse($r->sucesso());
		self::assertStringContainsString('HTTP 403', $r->resumoErros());
	}

	public function testUrlsDasConsultasEHead(): void
	{
		$http = (new ClienteHttpFalso())->responder(200, ['chaveAcesso' => self::CHAVE])->responder(404, '')->responder(200, '')->responder(200, [])->responder(200, []);
		$sefin = new SefinNacional(Ambiente::Producao, $http, 'https://proxy.local/sefin/');
		$id = 'DPS355030821234567800019500001000000000000042';

		self::assertSame(self::CHAVE, $sefin->consultarChavePorDps($id)->chaveAcesso());
		self::assertFalse($sefin->dpsProcessada($id));
		self::assertTrue($sefin->dpsProcessada($id));
		$sefin->consultarEventos(self::CHAVE, '101101', 1);
		$sefin->consultarParametrosServico('3550308', '010701');

		self::assertSame(['GET', 'https://proxy.local/sefin/dps/'.$id], [$http->chamadas[0]['metodo'], $http->chamadas[0]['url']]);
		self::assertSame('HEAD', $http->chamadas[1]['metodo']);
		self::assertSame('https://proxy.local/sefin/nfse/'.self::CHAVE.'/eventos/101101/1', $http->chamadas[3]['url']);
		self::assertSame('https://proxy.local/sefin/parametros_municipais/3550308/010701', $http->chamadas[4]['url']);
	}

	public function testRegistrarEventoUsaOCampoCerto(): void
	{
		$http = (new ClienteHttpFalso())->responder(200, ['eventoXmlGZipB64' => Gzip::comprimirBase64('<evento/>')]);
		$r = (new SefinNacional(Ambiente::Homologacao, $http))->registrarEvento(self::CHAVE, '<pedRegEvento/>');

		self::assertTrue($r->sucesso());
		self::assertSame('<evento/>', $r->xmls()['evento']);
		self::assertSame('<pedRegEvento/>', Gzip::descomprimirBase64($http->ultimoJson()['pedidoRegistroEventoXmlGZipB64']));
		self::assertStringEndsWith('/nfse/'.self::CHAVE.'/eventos', $http->chamadas[0]['url']);
	}

	public function testChaveInvalidaNemChegaNaRede(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		(new SefinNacional(Ambiente::Homologacao, new ClienteHttpFalso()))->consultarNfse('123');
	}
}
