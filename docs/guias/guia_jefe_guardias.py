"""Guía breve para el Jefe de Guardias (rol Responsable de SyH). Genera un PDF A4."""
import sys
from reportlab.lib.pagesizes import A4
from reportlab.lib.units import mm
from reportlab.lib import colors
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.enums import TA_LEFT, TA_CENTER
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import (BaseDocTemplate, Frame, PageTemplate, Paragraph, Spacer, Table, TableStyle,
                                KeepTogether, Image, PageBreak)

OUT, LOGO = sys.argv[1], sys.argv[2]
F = '/System/Library/Fonts/Supplemental/'
pdfmetrics.registerFont(TTFont('Arial', F + 'Arial.ttf'))
pdfmetrics.registerFont(TTFont('Arial-Bold', F + 'Arial Bold.ttf'))
pdfmetrics.registerFont(TTFont('Arial-Italic', F + 'Arial Italic.ttf'))
from reportlab.pdfbase.pdfmetrics import registerFontFamily
registerFontFamily('Arial', normal='Arial', bold='Arial-Bold', italic='Arial-Italic', boldItalic='Arial-Bold')

BRAND = colors.HexColor('#353c4f')
ACCENT = colors.HexColor('#f2c014')
SOFT = colors.HexColor('#f4f5f8')
LINE = colors.HexColor('#d9dce3')
MUTED = colors.HexColor('#5d6475')
RED = colors.HexColor('#b42318')
REDBG = colors.HexColor('#fdecea')
GREEN = colors.HexColor('#1e7b46')
GREENBG = colors.HexColor('#e8f5ee')

base = ParagraphStyle('base', fontName='Arial', fontSize=10, leading=14, textColor=colors.HexColor('#1f2430'))
small = ParagraphStyle('small', parent=base, fontSize=8.8, leading=12, textColor=MUTED)
h1 = ParagraphStyle('h1', parent=base, fontName='Arial-Bold', fontSize=15, leading=19, textColor=BRAND, spaceBefore=4, spaceAfter=6)
h2 = ParagraphStyle('h2', parent=base, fontName='Arial-Bold', fontSize=11, leading=15, textColor=BRAND, spaceBefore=8, spaceAfter=3)
cell = ParagraphStyle('cell', parent=base, fontSize=9.2, leading=12.5)
cellb = ParagraphStyle('cellb', parent=cell, fontName='Arial-Bold')
white = ParagraphStyle('white', parent=base, textColor=colors.white)
bullet = ParagraphStyle('bullet', parent=base, leftIndent=12, bulletIndent=2, spaceAfter=2)

W = A4[0] - 36 * mm


def P(t, s=base):
    return Paragraph(t, s)


def bullets(items):
    return [Paragraph(i, bullet, bulletText='•') for i in items]


def section(num, title):
    badge = Table([[P(f'<b>{num}</b>', ParagraphStyle('n', parent=base, alignment=TA_CENTER, textColor=BRAND, fontName='Arial-Bold')),
                    P(title, h1)]], colWidths=[9 * mm, W - 9 * mm])
    badge.setStyle(TableStyle([('BACKGROUND', (0, 0), (0, 0), ACCENT), ('VALIGN', (0, 0), (-1, -1), 'MIDDLE'),
                               ('LEFTPADDING', (1, 0), (1, 0), 8), ('TOPPADDING', (0, 0), (-1, -1), 2), ('BOTTOMPADDING', (0, 0), (-1, -1), 2)]))
    return badge


def table(rows, widths, head=True):
    data = [[P(c, cellb if (head and r == 0) else cell) for c in row] for r, row in enumerate(rows)]
    t = Table(data, colWidths=widths, repeatRows=1 if head else 0)
    st = [('GRID', (0, 0), (-1, -1), 0.5, LINE), ('VALIGN', (0, 0), (-1, -1), 'TOP'),
          ('TOPPADDING', (0, 0), (-1, -1), 4), ('BOTTOMPADDING', (0, 0), (-1, -1), 4), ('LEFTPADDING', (0, 0), (-1, -1), 6)]
    if head:
        st += [('BACKGROUND', (0, 0), (-1, 0), SOFT)]
    t.setStyle(TableStyle(st))
    return t


def box(content, bg=SOFT, border=LINE):
    t = Table([[content]], colWidths=[W])
    t.setStyle(TableStyle([('BACKGROUND', (0, 0), (-1, -1), bg), ('BOX', (0, 0), (-1, -1), 0.6, border),
                           ('LEFTPADDING', (0, 0), (-1, -1), 9), ('RIGHTPADDING', (0, 0), (-1, -1), 9),
                           ('TOPPADDING', (0, 0), (-1, -1), 7), ('BOTTOMPADDING', (0, 0), (-1, -1), 7)]))
    return t


def footer(c, doc):
    c.saveState()
    c.setFont('Arial', 8)
    c.setFillColor(MUTED)
    c.drawString(18 * mm, 10 * mm, 'Security App · Guía para el Jefe de Guardias')
    c.drawRightString(A4[0] - 18 * mm, 10 * mm, f'Página {doc.page}')
    c.setStrokeColor(ACCENT)
    c.setLineWidth(2)
    c.line(18 * mm, A4[1] - 12 * mm, A4[0] - 18 * mm, A4[1] - 12 * mm)
    c.restoreState()


doc = BaseDocTemplate(OUT, pagesize=A4, leftMargin=18 * mm, rightMargin=18 * mm, topMargin=17 * mm, bottomMargin=16 * mm,
                      title='Guía para el Jefe de Guardias', author='Security App')
doc.addPageTemplates([PageTemplate(id='p', frames=[Frame(18 * mm, 16 * mm, W, A4[1] - 33 * mm, id='f')], onPage=footer)])
s = []

# ---------- Portada ----------
cover = Table([[Image(LOGO, 20 * mm, 20 * mm),
                [P('<font size=20><b>Guía para el Jefe de Guardias</b></font>', ParagraphStyle('t', parent=white, leading=25)),
                 Spacer(1, 3), P('Cómo funciona el sistema de guardias, qué ves vos y qué hacer en cada caso', ParagraphStyle('st', parent=white, fontSize=10.5, textColor=colors.HexColor('#e7e9ef')))]]],
              colWidths=[26 * mm, W - 26 * mm])
cover.setStyle(TableStyle([('BACKGROUND', (0, 0), (-1, -1), BRAND), ('VALIGN', (0, 0), (-1, -1), 'MIDDLE'),
                           ('LEFTPADDING', (0, 0), (-1, -1), 10), ('TOPPADDING', (0, 0), (-1, -1), 12), ('BOTTOMPADDING', (0, 0), (-1, -1), 12)]))
s += [cover, Spacer(1, 10)]
s.append(P('Esta guía es para quien coordina a los guardias. Tu usuario tiene el rol <b>Responsable de Seguridad e Higiene</b>: '
           'ves todo lo de rondas, turnos, libro de guardia y avisos de la empresa. Los usuarios del sistema y la configuración '
           'los maneja el administrador.'))
s.append(Spacer(1, 8))

s.append(section('1', 'El sistema en tres piezas'))
three = Table([[P('<b>Celular del puesto</b>', cellb), P('<b>Notebook del puesto</b>', cellb), P('<b>Tu panel web</b>', cellb)],
               [P('Lo usan los guardias. Un solo celular para todos.<br/><br/>• Empezar y terminar el turno<br/>• Cambio de guardia<br/>'
                  '• Rondas: escanear QR o NFC<br/>• Libro de guardia<br/>• Botón de pánico', cell),
                P('Queda siempre abierta en el libro de guardia.<br/><br/>• Anotar novedades<br/>• Ingreso y salida de personas y vehículos<br/>'
                  '• Ver quién está de turno<br/><br/><i>No se empieza el turno acá: solo desde el celular (por el GPS).</i>', cell),
                P('Lo usás vos desde cualquier computadora o celular con internet.<br/><br/>• Ver qué pasa en vivo<br/>• Recibir avisos<br/>'
                  '• Preparar guardias, puestos y rondas<br/>• Reportes', cell)]],
              colWidths=[W / 3] * 3)
three.setStyle(TableStyle([('GRID', (0, 0), (-1, -1), 0.5, LINE), ('BACKGROUND', (0, 0), (-1, 0), ACCENT), ('VALIGN', (0, 0), (-1, -1), 'TOP'),
                           ('TOPPADDING', (0, 0), (-1, -1), 5), ('BOTTOMPADDING', (0, 0), (-1, -1), 6), ('LEFTPADDING', (0, 0), (-1, -1), 6)]))
s += [three, Spacer(1, 6)]
s.append(box(P('<b>Los guardias no tienen usuario ni contraseña.</b> Se cargan como <b>empleados</b> y cada uno tiene un '
               '<b>PIN</b> de 4 a 6 números. En el celular eligen su nombre y ponen su PIN: así todo lo que hacen queda a su nombre.')))

# ---------- Un turno ----------
s.append(Spacer(1, 6))
s.append(section('2', 'Cómo es un turno, paso a paso'))
steps = [
    ['<b>1. Empieza el turno</b>', 'En el celular: <b>Empezar mi turno</b> » elige su nombre » pone su PIN. El celular toma la ubicación (tiene que estar en el predio).'],
    ['<b>2. Hace las rondas</b>', 'Escanea el QR (o apoya el celular en la etiqueta NFC) de cada punto. Si no tiene señal, el celular guarda todo y lo manda después.'],
    ['<b>3. Anota en el libro</b>', 'Novedades, personas y vehículos que entran y salen. Puede hacerlo en el celular o en la notebook. Si alguien no está en la lista de autorizados, igual se anota y te avisa.'],
    ['<b>4. Cambio de guardia</b>', 'El que llega toca <b>Cambio de guardia</b> y pone su PIN. El turno del anterior se cierra solo.'],
    ['<b>5. Termina el turno</b>', '<b>Terminar mi turno</b>. Si no viene nadie, puede elegir <b>Dejar el puesto sin persona</b> y escribir el motivo.'],
    ['<b>6. Reporte</b>', 'Al terminar la franja horaria llega por email el <b>reporte de fin de turno</b>: quién cubrió, ingresos y salidas, novedades, rondas y alertas.'],
]
s.append(table(steps, [38 * mm, W - 38 * mm], head=False))
s.append(Spacer(1, 6))
s.append(box([P('<b>Reglas de oro para los guardias</b>', ParagraphStyle('rg', parent=base, textColor=GREEN)),
              Spacer(1, 2),
              *bullets(['Siempre <b>empezar el turno en el celular</b> antes de hacer cualquier otra cosa.',
                        'El PIN es personal: no se presta.',
                        'Al irse: <b>Cambio de guardia</b> si llegó el otro, o <b>Terminar mi turno</b>.',
                        'Celular cargado, con ubicación prendida y sin ahorro de batería.',
                        'Ante un peligro: mantener apretado el botón de <b>pánico</b> 2 segundos.'])], bg=GREENBG, border=colors.HexColor('#b7dfc6')))


# ---------- Lo que mirás ----------
s.append(PageBreak())
s.append(section('3', 'Tu panel: qué mirar cada día'))
s.append(P('Entrás a <b>indumor.com/seguridad</b> con tu email y contraseña. En el menú de la izquierda usás sobre todo <b>Rondas</b> y <b>Libro de guardia</b>.', base))
s.append(Spacer(1, 5))
s.append(table([
    ['Dónde', 'Qué ves'],
    ['<b>Inicio</b>', 'Resumen del día y lo que tenés pendiente.'],
    ['<b>Campanita</b> (arriba)', 'Tus avisos. Los urgentes también te llegan al celular y por email.'],
    ['<b>Rondas » Hoy</b>', 'Rondas en curso (con el guardia en el mapa, en vivo), las programadas del día, alertas y el estado de los celulares (batería, GPS).'],
    ['<b>Rondas » Turnos » Hoy » Puestos</b>', 'Una barra por puesto: <font color="#1e7b46"><b>verde</b></font> = hay guardia, <font color="#b42318"><b>rojo</b></font> = no hay nadie, '
     '<b>gris</b> = se dejó sin persona con motivo. Tocando la barra ves quién estuvo, los relevos y el reporte.'],
    ['<b>Libro de guardia</b>', 'Lo anotado en el día por predio, quién está <b>adentro ahora</b> y el cartel de quién está de turno. Podés anotar vos también.'],
    ['<b>Rondas » Rondas realizadas</b>', 'Historial con filtros por fecha, guardia y ruta. Cada ronda muestra el recorrido, los puntos salteados y las marcas fuera de lugar.'],
], [52 * mm, W - 52 * mm]))

s.append(Spacer(1, 6))
s.append(section('4', 'Cuando llega un aviso'))
s.append(table([
    ['Aviso', 'Qué hacer'],
    ['<font color="#b42318"><b>Pánico</b></font>', 'Llamá al guardia o a quien corresponda. Abrí el aviso (trae la ubicación) y tocá <b>Atendido</b> con un comentario. Hasta que alguien lo atienda, el aviso se repite.'],
    ['<font color="#b42318"><b>Condición insegura</b></font>', 'Llega al instante. El sistema ya abrió una observación: revisala en <b>Observaciones</b> y asigná la acción.'],
    ['<b>Puesto sin cubrir / terminó sin relevo</b>', 'Nadie empezó el turno a horario, o el guardia se fue y no llegó el siguiente. Comunicate con la empresa de seguridad.'],
    ['<b>Guardia sin señal / GPS apagado / batería baja</b>', 'Llamalo para ver que esté bien y que el celular esté cargado y con ubicación.'],
    ['<b>Marca fuera de lugar</b>', 'Escaneó un punto lejos de donde está. Revisalo en el detalle de la ronda (puede ser un QR movido).'],
    ['<b>Ingreso no autorizado</b>', 'Entró alguien que no está en la lista de autorizados (o fuera de horario). Verificá con el área que lo recibió.'],
    ['<b>Anotación sin guardia de turno</b>', 'Se escribió en el libro sin que nadie haya empezado el turno. Recordales que primero hay que empezar el turno en el celular.'],
], [52 * mm, W - 52 * mm]))
s.append(Spacer(1, 5))
s.append(P('Para cambiar quién recibe cada aviso o los tiempos de tolerancia (por ejemplo, cuántos minutos esperar un relevo), pedíselo al administrador.', small))

# ---------- Preparación ----------
s.append(PageBreak())
s.append(section('5', 'Preparación: se hace una vez (o cuando algo cambia)'))
prep = [
    ['Tarea', 'Dónde', 'Cómo'],
    ['<b>Cargar a los guardias</b>', 'Datos maestros » Empleados', 'Nombre, apellido, DNI y la empresa de seguridad como contratista.'],
    ['<b>Ponerles el PIN</b>', 'Rondas » Turnos » Guardias y PIN', 'Un PIN de 4 a 6 números por guardia. Dáselo a cada uno en persona.'],
    ['<b>Armar el puesto</b>', 'Rondas » Turnos » Programación » Nueva', 'Modo <b>Por puesto</b>, el horario a cubrir, los guardias que pueden cubrirlo y el <b>celular compartido</b> (la cuenta del puesto).'],
    ['<b>Puntos de ronda</b>', 'Rondas » Puntos', 'Ubicarlos en el mapa, imprimir el QR y pegarlo en el lugar.'],
    ['<b>Rutas y horarios de ronda</b>', 'Rondas » Rutas y Rondas » Programación', 'Qué puntos recorrer, en qué orden y a qué hora.'],
    ['<b>Lista de autorizados</b>', 'Libro de guardia » Lista de autorizados', 'Personas y vehículos que pueden entrar, con días y horario. Se puede importar desde Excel.'],
    ['<b>Notebook del puesto</b>', 'En la notebook', 'Ingresar <b>una sola vez</b> con la cuenta del puesto. Queda recordada; no hay que tocar "Salir".'],
]
s.append(table(prep, [38 * mm, 50 * mm, W - 88 * mm]))

s.append(Spacer(1, 6))
s.append(section('6', 'Reportes'))
s.append(table([
    ['Reporte', 'Para qué'],
    ['<b>Rondas » Reportes » Métricas</b>', 'Porcentaje de rondas a tiempo, marcas en el lugar, por guardia y por punto (los que más fallan primero).'],
    ['<b>Rondas » Reportes » Marcas fuera de lugar</b>', 'Escaneos lejos del punto o con GPS falso.'],
    ['<b>Turnos » Cobertura de puestos</b>', 'Horas a cubrir contra horas cubiertas, huecos justificados y sin justificar.'],
    ['<b>Turnos » Horas trabajadas</b>', 'Horas reales de cada guardia (se baja en Excel/CSV).'],
    ['<b>Libro » Buscar / Imprimir</b>', 'Buscar por DNI, patente o fecha, e imprimir el parte diario con folios y firmas.'],
], [62 * mm, W - 62 * mm]))

# ---------- Preguntas ----------
s.append(Spacer(1, 6))
s.append(section('7', 'Preguntas frecuentes'))
faq = [
    ('Un guardia se olvidó el PIN.', 'Poné uno nuevo en <b>Guardias y PIN</b>. Llega al celular en la próxima sincronización (deslizar hacia abajo en el inicio).'),
    ('Entra un guardia nuevo.', 'Cargalo como empleado, ponele PIN y agregalo al grupo del puesto en la programación.'),
    ('Un guardia deja de trabajar.', 'Desactivalo en <b>Datos maestros » Empleados</b>. Deja de aparecer en el celular; su historial queda.'),
    ('¿Se puede borrar algo del libro?', 'No. Para corregir, se usa <b>Corregir</b> y queda una anotación nueva. Así el libro es válido ante una auditoría.'),
    ('No hay señal en el predio.', 'El celular guarda todo y lo manda solo cuando vuelve la señal. El pánico, sin datos, se manda por SMS.'),
    ('Se apagó la notebook.', 'Al prenderla y abrir el navegador vuelve a entrar sola al libro.'),
]
for q, a in faq:
    s.append(KeepTogether([P(f'<b>{q}</b>', base), P(a, ParagraphStyle('a', parent=base, leftIndent=10, spaceAfter=4, textColor=colors.HexColor('#333a48')))]))

doc.build(s)
print('ok', OUT)
