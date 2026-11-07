<?php declare(strict_types=1);

namespace Arijs\NfseNacional\Tests\Unit;

use Arijs\NfseNacional\Danfse\DanfseOficial;
use Arijs\NfseNacional\Danfse\QrCodeByte;
use PHPUnit\Framework\TestCase;

/**
 * DANFSe no leiaute oficial, a partir do XML autorizado REAL de uma nota da
 * produção restrita (assinaturas removidas). Os valores esperados são os do
 * DANFSe que o Emissor Nacional gerou para a mesma nota.
 */
final class DanfseOficialTest extends TestCase
{
	private static function xml(): string
	{
		return (string) file_get_contents(__DIR__.'/../fixtures/nfse-autorizada-teste.xml');
	}

	public function testCamposSaemNoFormatoDoOficial(): void
	{
		$c = (new DanfseOficial())->campos(self::xml());

		$this->assertSame('35503082203172223000124000000000000326114655034896', $c['chave']);
		$this->assertSame('São Paulo - SP', $c['municipio_emissor']);
		$this->assertSame(['2', '2', '1'], [$c['amb_gerador'], $c['tp_amb'], $c['sem_validade']]);
		$this->assertSame(['3', '01/11/2026', '06/11/2026 18:32:49'], [$c['numero'], $c['competencia'], $c['dh_nfse']]);
		$this->assertSame(['1', '1', '06/11/2026 18:32:46'], [$c['n_dps'], $c['serie'], $c['dh_dps']]);
		$this->assertSame(['Prestador', 'NFS-e Gerada', 'NFS-e regular'], [$c['emitente'], $c['situacao'], $c['finalidade']]);

		$this->assertSame('03.172.223/0001-24', $c['prest_doc']);
		$this->assertSame('000000028065646', $c['prest_im']);
		$this->assertSame('São Paulo / SP', $c['prest_mun']);
		$this->assertSame('35.50308 / 04.332-050', $c['prest_ibge_cep']);
		$this->assertSame('Avenida José Estevão de Magalhães, 00052, SALA 01, Vila Campestre', $c['prest_end']);
		// O oficial corta por número de caracteres (40), não por largura.
		$this->assertSame('Optante - Microempresa ou Empresa de ...', $c['simples']);
		$this->assertSame('Regime de apuração dos tributos federais e municipal pelo Simples Nacional', $c['regime_sn']);

		$this->assertSame('12.457.402/0001-07', $c['toma_doc']);
		$this->assertSame('São Paulo / SP', $c['toma_mun']);
		$this->assertSame('35.50308 / 04.773-090', $c['toma_ibge_cep']);
		$this->assertSame('Rua ESTORIL, 285, VELEIROS', $c['toma_end']);
		$this->assertSame('', $c['destinatario']);

		$this->assertSame('01.07.01 / 001', $c['trib_nac_mun']);
		$this->assertSame('1.1510.00.00', $c['nbs']);
		$this->assertSame('São Paulo / SP / -', $c['local_prestacao']);
		$this->assertStringStartsWith('Suporte técnico em informática', $c['desc_trib']);
		$this->assertStringEndsWith('(Vigência a partir de 13/02/2018 - Instru...', $c['desc_trib']);
		$this->assertSame(170, mb_strlen($c['desc_trib']));
		$this->assertSame('Licença de uso DDSOFT - competência nov/2026. Pagamento #2083.', $c['descricao']);
		$this->assertSame(['Operação Tributável', 'Não Retido', 'São Paulo / SP / -'], [$c['trib_issqn'], $c['retencao'], $c['incidencia']]);
		$this->assertSame(['R$ 1,00', 'R$ 1,00', '', 'R$ 1,00'], [$c['v_servico'], $c['v_liquido'], $c['v_retencoes'], $c['v_liquido_ibscbs']]);
		$this->assertSame('Totais aproximados dos Tributos cfe. Lei n° 12.741/2012: Federais: -; Estaduais: -; Municipais: -;', $c['info']);
		// Nota de teste: o QR leva à consulta da PRODUÇÃO RESTRITA.
		$this->assertSame('https://www.producaorestrita.nfse.gov.br/ConsultaPublica?tpc=1&chave=35503082203172223000124000000000000326114655034896', $c['qr']);
	}

	/**
	 * O QR do Emissor Nacional é versão 7 (um segmento em modo byte); o
	 * tc-lib-barcode, sozinho, faria versão 6. A matriz esperada foi lida da
	 * imagem do DANFSe oficial da nota #913.
	 */
	public function testQrCodeIgualAoDoEmissorNacionalModuloAModulo(): void
	{
		$oficial = array_values(array_filter(array_map('trim', file(__DIR__.'/../fixtures/qr-oficial-nota-913.txt') ?: [])));
		$conteudo = (new DanfseOficial())->campos(self::xml())['qr'];

		$grade = (new QrCodeByte($conteudo, 1, 0, false))->getGrid();

		$this->assertCount(45, $grade, 'versão 7');
		$this->assertSame($oficial, $grade);
	}

	public function testTomadorDeOutroMunicipioUsaOResolvedorEAUfDoCodigoIbge(): void
	{
		$xml = str_replace('<cMun>3550308</cMun>
              <CEP>04773090</CEP>', '<cMun>3304557</cMun>
              <CEP>20040002</CEP>', self::xml());
		$this->assertNotSame(self::xml(), $xml, 'fixture mudou: o tomador não está mais nesse formato');

		$pedidos = [];
		$c = (new DanfseOficial(static function (string $cMun) use (&$pedidos): ?string {
			$pedidos[] = $cMun;

			return '3304557' === $cMun ? 'Rio de Janeiro' : null;
		}))->campos($xml);

		$this->assertSame('Rio de Janeiro / RJ', $c['toma_mun']);
		$this->assertSame('33.04557 / 20.040-002', $c['toma_ibge_cep']);
		$this->assertSame(['3304557'], $pedidos);
	}

	public function testNotaCanceladaDizIssoNaSituacao(): void
	{
		$this->assertSame('NFS-e Cancelada', (new DanfseOficial())->campos(self::xml(), cancelada: true)['situacao']);
	}

	public function testGeraUmPdfDeUmaPagina(): void
	{
		$pdf = (new DanfseOficial())->gerar(self::xml());

		$this->assertStringStartsWith('%PDF-', $pdf);
		$this->assertSame(1, preg_match_all('#/Type\s*/Page\b#', $pdf), 'uma página só');
		$this->assertGreaterThan(10_000, \strlen($pdf));
		// O QR vai em bitmap como no oficial: 490 × 490 px, suavizado ao reduzir.
		$this->assertMatchesRegularExpression('#/Subtype /Image /Width 490 /Height 490 .*?/Interpolate true#s', $pdf);
	}

	/** O Creator do PDF é da aplicação; sem informar, o nome do pacote. */
	public function testCriadorDoPdf(): void
	{
		// A lib grava os metadados em UTF-16BE com BOM.
		$utf16 = static fn (string $s): string => "\xFE\xFF".mb_convert_encoding($s, 'UTF-16BE', 'UTF-8');

		$this->assertStringContainsString('/Creator ('.$utf16('arijs/nfse-nacional').')', (new DanfseOficial())->gerar(self::xml(), comprimir: false));
		$this->assertStringContainsString('/Creator ('.$utf16('Meu ERP').')', (new DanfseOficial(criador: 'Meu ERP'))->gerar(self::xml(), comprimir: false));
	}

	/** Sem resolvedor, o tomador de outro município sai com o código IBGE no lugar do nome. */
	public function testSemResolvedorSaiOCodigoDoMunicipio(): void
	{
		$xml = str_replace('<cMun>3550308</cMun>
              <CEP>04773090</CEP>', '<cMun>3304557</cMun>
              <CEP>20040002</CEP>', self::xml());

		$this->assertSame('3304557 / RJ', (new DanfseOficial())->campos($xml)['toma_mun']);
	}

	/**
	 * A linha divisória de cada seção com faixa cinza é desenhada POR CIMA da
	 * faixa: na ordem inversa a faixa cobria metade do traço.
	 */
	public function testLinhaDaSecaoVemDepoisDaFaixaCinza(): void
	{
		$pdf = (new DanfseOficial())->gerar(self::xml(), comprimir: false);
		$pt = static fn (float $mm): string => sprintf('%F', 841.89 - $mm * 72 / 25.4);

		foreach ([44.30, 71.40, 97.70, 115.63, 129.27, 142.90, 169.99] as $y) {
			$faixa = strpos($pdf, "8.503937 {$pt($y + 0.09)} m");
			$linha = strrpos($pdf, "8.503937 {$pt($y)} m\n586.771654 {$pt($y)} l");
			$this->assertNotFalse($faixa, "faixa da seção em {$y} mm");
			$this->assertNotFalse($linha, "linha da seção em {$y} mm");
			$this->assertGreaterThan($faixa, $linha, "a linha em {$y} mm ficou por baixo da faixa");
		}
	}

	/** A imagem do QR no formato da oficial: 10 px por módulo + 2 módulos de margem. */
	public function testPngDoQrNoFormatoDaImagemOficial(): void
	{
		$png = DanfseOficial::pngQr(['101', '010', '101']);

		$this->assertSame("\x89PNG", substr($png, 0, 4));
		$this->assertSame([70, 70], array_values(unpack('N2', substr($png, 16, 8)) ?: []));
	}

	public function testXmlQueNaoENfseERecusado(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		(new DanfseOficial())->campos('<DPS xmlns="http://www.sped.fazenda.gov.br/nfse"/>');
	}

	public function testFormatos(): void
	{
		$this->assertSame('(11) 94504-3535', DanfseOficial::fone('11945043535'));
		$this->assertSame('(11) 4504-3535', DanfseOficial::fone('1145043535'));
		$this->assertSame('123.456.789-09', DanfseOficial::doc('12345678909'));
		$this->assertSame('RJ', DanfseOficial::uf('3304557'));
		$this->assertSame('-', DanfseOficial::uf(''));
		$this->assertSame('R$ 11.000,00', DanfseOficial::reais('11000.00'));
		$this->assertSame('2,00%', DanfseOficial::pct('2'));
		$this->assertSame('abc', DanfseOficial::limitar('abc', 3));
		$this->assertSame('a...', DanfseOficial::limitar('abcde', 4));
		$this->assertSame('https://www.nfse.gov.br/ConsultaPublica?tpc=1&chave=1', DanfseOficial::linkConsulta('1', '1'));
	}
}
