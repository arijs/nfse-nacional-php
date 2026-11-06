<?php declare(strict_types=1);

namespace Arijs\NfseNacional\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Arijs\NfseNacional\Dps\DpsXml;
use Arijs\NfseNacional\Excecao\DadosExcecao;
use Arijs\NfseNacional\Tests\Support\DpsExemplo;
use Arijs\NfseNacional\Xsd\ValidadorXsd;

final class DpsXmlTest extends TestCase
{
	public function testGeraXmlValidoContraXsdOficial(): void
	{
		$xml = DpsXml::gerar(DpsExemplo::infDps());

		self::assertSame([], (new ValidadorXsd())->validar($xml, 'DPS'));
		self::assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $xml);
		self::assertStringContainsString('<DPS xmlns="http://www.sped.fazenda.gov.br/nfse" versao="1.01">', $xml);
	}

	public function testIdDoInfDpsTem45PosicoesNoFormatoDoLeiaute(): void
	{
		$xml = DpsXml::gerar(DpsExemplo::infDps());

		// "DPS" + cLocEmi(7) + tipo(1: 2=CNPJ) + inscrição(14) + série(5) + nDPS(15)
		self::assertStringContainsString('<infDPS Id="DPS355030821234567800019500001000000000000042">', $xml);
		self::assertSame(45, \strlen('DPS355030821234567800019500001000000000000042'));
	}

	public function testIdComCpfCompletaZerosAEsquerda(): void
	{
		self::assertSame('DPS355030810001234567890100007000000000000001', DpsXml::idDps('3550308', '123.456.789-01', 7, 1));
	}

	public function testIdRejeitaNumeroZeroEInscricaoInvalida(): void
	{
		$this->expectException(DadosExcecao::class);
		DpsXml::idDps('3550308', '12345678000195', 1, 0);
	}

	public function testReordenaElementosConformeXsd(): void
	{
		$inf = DpsExemplo::infDps();
		// Embaralha de propósito: tpEmit antes de tpAmb, valores antes de serv,
		// xNome antes do CNPJ do tomador.
		$fora = ['tpEmit' => $inf['tpEmit'], 'valores' => $inf['valores'], 'serv' => $inf['serv']] + $inf;
		$fora['toma'] = ['xNome' => $inf['toma']['xNome']] + $inf['toma'];

		$xml = DpsXml::gerar($fora);

		self::assertSame([], (new ValidadorXsd())->validar($xml, 'DPS'));
		self::assertLessThan(strpos($xml, '<tpEmit>'), strpos($xml, '<tpAmb>'));
		self::assertLessThan(strpos($xml, '<valores>'), strpos($xml, '<serv>'));
		self::assertLessThan(strpos($xml, '<xNome>'), strpos($xml, '<toma><CNPJ>'));
	}

	public function testFormataDecimaisDatasEListas(): void
	{
		$inf = DpsExemplo::infDps();
		$inf['valores']['vServPrest']['vServ'] = 1234.5;
		$inf['valores']['vDescCondIncond'] = ['vDescIncond' => 10];
		$inf['serv']['infoCompl'] = ['gItemPed' => ['xItemPed' => ['A1', 'A2']], 'xInfComp' => 'obs'];
		$inf['dhEmi'] = new \DateTimeImmutable('2026-01-02T03:04:05-03:00');
		$inf['dCompet'] = new \DateTimeImmutable('2026-01-02');

		$xml = DpsXml::gerar($inf);

		self::assertStringContainsString('<vServ>1234.50</vServ>', $xml);
		self::assertStringContainsString('<vDescIncond>10.00</vDescIncond>', $xml);
		self::assertStringContainsString('<gItemPed><xItemPed>A1</xItemPed><xItemPed>A2</xItemPed></gItemPed>', $xml);
		self::assertStringContainsString('<dhEmi>2026-01-02T03:04:05-03:00</dhEmi>', $xml);
		self::assertStringContainsString('<dCompet>2026-01-02</dCompet>', $xml);
		self::assertSame([], (new ValidadorXsd())->validar($xml, 'DPS'));
	}

	public function testNuloEOmitidoEBooleanoERecusado(): void
	{
		$inf = DpsExemplo::infDps();
		$inf['toma']['email'] = null;
		$xml = DpsXml::gerar($inf);
		self::assertStringNotContainsString('<email>', $xml);

		$inf['toma']['email'] = true;
		$this->expectException(DadosExcecao::class);
		DpsXml::gerar($inf);
	}

	public function testXsdApontaCampoInvalido(): void
	{
		$inf = DpsExemplo::infDps();
		$inf['toma']['CNPJ'] = '123'; // TSCNPJ exige 14 dígitos

		$erros = (new ValidadorXsd())->validar(DpsXml::gerar($inf), 'DPS');

		self::assertNotSame([], $erros);
		self::assertStringContainsString('CNPJ', implode("\n", $erros));
	}

	public function testEscapaCaracteresEspeciais(): void
	{
		$inf = DpsExemplo::infDps();
		$inf['serv']['cServ']['xDescServ'] = 'Suporte & manutenção <mensal>';
		$xml = DpsXml::gerar($inf);

		self::assertStringContainsString('<xDescServ>Suporte &amp; manutenção &lt;mensal&gt;</xDescServ>', $xml);
		self::assertSame([], (new ValidadorXsd())->validar($xml, 'DPS'));
	}
}
