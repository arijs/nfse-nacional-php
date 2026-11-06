<?php declare(strict_types=1);

namespace Arijs\NfseNacional\Tests\Unit;

use Arijs\NfseNacional\Adn\AdnContribuintes;
use Arijs\NfseNacional\Adn\LoteDfe;
use Arijs\NfseNacional\Ambiente;
use Arijs\NfseNacional\Excecao\DadosExcecao;
use Arijs\NfseNacional\Excecao\SefinExcecao;
use Arijs\NfseNacional\Tests\Support\ClienteHttpFalso;
use Arijs\NfseNacional\Util\Gzip;
use PHPUnit\Framework\TestCase;

final class AdnContribuintesTest extends TestCase
{
	private const CHAVE_A = '35503082123456780001950000000000012026110000000017';
	private const CHAVE_B = '35503082987654320001880000000000052026110000000021';

	/** @return array<string,mixed> */
	private static function item(int $nsu, string $tipo, string $chave, ?string $evento = null, string $xml = '<x/>'): array
	{
		return [
			'NSU' => $nsu,
			'TipoDocumento' => $tipo,
			'ChaveAcesso' => $chave,
			'TipoEvento' => $evento,
			'DataHoraGeracao' => '2026-11-06T13:00:00.123-03:00',
			'ArquivoXml' => Gzip::comprimirBase64($xml),
		];
	}

	public function testDistribuirInterpretaUmaPagina(): void
	{
		$http = (new ClienteHttpFalso())->responder(200, [
			'StatusProcessamento' => 'ProcessamentoNormal',
			'LoteDFe' => [
				self::item(101, 'NFSE', self::CHAVE_A, null, '<NFSe>a</NFSe>'),
				self::item(102, 'EVENTO', self::CHAVE_A, 'CANCELAMENTO', '<evento/>'),
				self::item(103, 'NFSE', self::CHAVE_B),
			],
		]);
		$adn = new AdnContribuintes(Ambiente::Homologacao, $http);

		$lote = $adn->distribuir(100, '12.345.678/0001-95');

		self::assertSame('https://adn.producaorestrita.nfse.gov.br/contribuintes/DFe/100?lote=true&cnpjConsulta=12345678000195', $http->chamadas[0]['url']);
		self::assertSame('GET', $http->chamadas[0]['metodo']);
		self::assertCount(3, $lote->documentos);
		self::assertSame(103, $lote->ultimoNsu);
		self::assertSame(LoteDfe::STATUS_NORMAL, $lote->statusProcessamento);
		self::assertCount(2, $lote->nfses());
		self::assertCount(1, $lote->eventos());
		self::assertSame([self::CHAVE_A], $lote->chavesCanceladas());
		self::assertSame('<NFSe>a</NFSe>', $lote->documentos[0]->xml());
		self::assertTrue($lote->documentos[1]->cancelaNota());
		self::assertSame('2026-11-06T13:00:00.123-03:00', $lote->documentos[0]->dataHoraGeracao);
	}

	public function testSincronizarPaginaAteAcabar(): void
	{
		$http = (new ClienteHttpFalso())
			->responder(200, ['StatusProcessamento' => 'ProcessamentoNormal', 'LoteDFe' => [self::item(1, 'NFSE', self::CHAVE_A), self::item(2, 'NFSE', self::CHAVE_B)]])
			->responder(200, ['StatusProcessamento' => 'ProcessamentoNormal', 'LoteDFe' => [self::item(3, 'EVENTO', self::CHAVE_B, 'SUBSTITUICAO')]])
			->responder(200, ['StatusProcessamento' => 'NenhumDocumentoLocalizado', 'LoteDFe' => []]);
		$adn = new AdnContribuintes(Ambiente::Producao, $http);
		$paginas = [];

		$lote = $adn->sincronizar(0, 20, null, static function (LoteDfe $p) use (&$paginas): void {
			$paginas[] = $p->ultimoNsu;
		});

		self::assertCount(3, $lote->documentos);
		self::assertSame(3, $lote->ultimoNsu);
		self::assertFalse($lote->temMais);
		self::assertSame(3, $lote->paginas);
		self::assertSame([2, 3], $paginas);
		self::assertSame([self::CHAVE_B], $lote->chavesCanceladas());
		self::assertStringStartsWith('https://adn.nfse.gov.br/contribuintes/DFe/0?', $http->chamadas[0]['url']);
		self::assertStringStartsWith('https://adn.nfse.gov.br/contribuintes/DFe/2?', $http->chamadas[1]['url']);
		self::assertStringStartsWith('https://adn.nfse.gov.br/contribuintes/DFe/3?', $http->chamadas[2]['url']);
		self::assertStringNotContainsString('cnpjConsulta', $http->chamadas[0]['url']);
	}

	public function testSincronizarRespeitaLimiteDePaginasESinalizaQueHaMais(): void
	{
		$http = (new ClienteHttpFalso())
			->responder(200, ['StatusProcessamento' => 'ProcessamentoNormal', 'LoteDFe' => [self::item(1, 'NFSE', self::CHAVE_A)]])
			->responder(200, ['StatusProcessamento' => 'ProcessamentoNormal', 'LoteDFe' => [self::item(2, 'NFSE', self::CHAVE_A)]]);
		$lote = (new AdnContribuintes(Ambiente::Producao, $http))->sincronizar(0, 2);

		self::assertSame(2, $lote->ultimoNsu);
		self::assertTrue($lote->temMais);
		self::assertSame(2, $lote->paginas);
	}

	public function testErroHttpViraSefinExcecao(): void
	{
		$http = (new ClienteHttpFalso())->responder(403, '');
		$this->expectException(SefinExcecao::class);
		(new AdnContribuintes(Ambiente::Producao, $http))->distribuir(0);
	}

	public function testDanfseExigePdf(): void
	{
		$http = (new ClienteHttpFalso())->responder(200, '%PDF-1.7 conteudo')->responder(200, '<html>fora do ar</html>');
		$adn = new AdnContribuintes(Ambiente::Producao, $http);

		self::assertSame('%PDF-1.7 conteudo', $adn->baixarDanfse(self::CHAVE_A));
		self::assertSame('https://adn.nfse.gov.br/danfse/'.self::CHAVE_A, $http->chamadas[0]['url']);
		self::assertSame('application/pdf', $http->chamadas[0]['cabecalhos']['Accept']);

		$this->expectException(DadosExcecao::class);
		$adn->baixarDanfse(self::CHAVE_A);
	}

	public function testEventosDaNotaNoAdn(): void
	{
		$http = (new ClienteHttpFalso())->responder(200, ['eventos' => []]);
		(new AdnContribuintes(Ambiente::Homologacao, $http, 'https://adn.local/'))->consultarEventos(self::CHAVE_A);
		self::assertSame('https://adn.local/contribuintes/NFSe/'.self::CHAVE_A.'/Eventos', $http->chamadas[0]['url']);
	}
}
