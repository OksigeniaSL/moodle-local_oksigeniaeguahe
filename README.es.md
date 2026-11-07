# Admisiones para Moodle (Oksigenia Eguahe)

[![Licencia: GPLv3](https://img.shields.io/badge/license-GPLv3+-blue.svg)](LICENSE)
![Moodle](https://img.shields.io/badge/Moodle-4.5%E2%80%935.3-orange)
![Estado](https://img.shields.io/badge/status-alpha-red)
[![Patrocinio](https://img.shields.io/badge/sponsor-Oksigenia-00d4ff)](https://oksigenia.com/es/open-source#sponsor)

**Oksigenia Eguahe** es la puerta de entrada de un colegio, una academia o un centro de formación, dentro de su sitio
Moodle™ o como servicio alojado. Las familias que piden información o plaza las reciben personas, y el equipo ve de un
vistazo qué hogares esperan respuesta, cuántas plazas le quedan a cada curso y cómo va la campaña. *Eguahe* viene de
*eguahẽ porã*, «bienvenido» en guaraní.

[Read in English](README.md)

## Estado

**Alfa.** Todavía no es para sitios en producción.

## Qué hace

- **El hogar es la unidad**: sus adultos y quienes estudiarían, cada uno con el curso que pide y hasta dónde ha llegado
  su camino (consulta, visita, solicitud, plaza ofrecida, lista de espera, matriculado o cerrado, con su motivo). Cada
  etapa significa lo mismo todos los años, así que las cifras de una campaña se comparan con las de la siguiente. Una
  familia que vuelve, con cualquiera de sus teléfonos o correos, nunca se crea dos veces.
- **Jornadas de puertas abiertas, visitas y otros actos** (una fiesta, una charla), con su aforo o por turnos, cada uno
  con el suyo; un QR para el cartel y una página para apuntarse que la familia rellena en su móvil, en español, inglés
  o portugués, sin cuenta. En la puerta, el equipo marca quién ha llegado y añade a quien llega sin haberse apuntado.
  Las familias que ya son del centro quedan marcadas y fuera de las cifras de la campaña.
- **La página de cada familia**, con un enlace privado: su próxima visita, con el archivo para su calendario, lo que
  el centro guarda de ella y dos botones para descargar sus datos o borrarlos cuando quiera.
- **La bandeja del equipo**: quién espera respuesta y desde cuándo, la espera más larga arriba; el historial de cada
  hogar; las respuestas, las notas y los permisos que ha dado cada adulto para cada canal.
- **Oferta y plazas**: por curso, los interesados, los que han visitado, los matriculados y las plazas libres, con la
  definición de cada cifra en pantalla.
- **Quien supervisa** ve todo lo de su centro sin contestar ni cambiar nada.
- **Ficheros tal como vienen**: una hoja de cálculo con todas sus pestañas, la exportación de los anuncios, las
  respuestas de un formulario o un CSV. Eguahe ve qué es cada hoja (contactos de una campaña, alumnos nuevos, bajas, la
  lista de un curso, totales, población) y qué lleva cada columna, lo enseña antes de importar, borra al entrar lo
  personal y convierte correos, teléfonos y códigos en claves que unen a la misma familia o alumno entre ficheros. No se
  rechaza nada.
- **Cifras que no engañan**: una campaña anterior importada seudonimizada, por hogares desde el primer contacto hasta
  la matrícula, por canal desde los dos extremos y con lo que costó cada uno por alumno nuevo; quién entró y quién se
  fue en cada curso, con el saldo y los motivos; la matrícula año a año. Cada cifra con su definición.
- **Varios centros en un mismo sitio**: cada fila lleva su centro, y cada centro tiene su equipo, su color, su logotipo,
  su zona y sus textos sobre los datos de sus familias.
- **Contra el spam**, sin terceros ni cookies: [ALTCHA](https://altcha.org) (MIT), una pequeña prueba de trabajo.
- **Privacidad**: las familias no son usuarios de Moodle; descargan y borran sus datos desde su página. Lo que el
  plugin guarda del equipo va por la API de privacidad de Moodle.

- **La previsión**: por cada curso de entrada, los alumnos que cabe esperar en los próximos años, en rangos, a partir
  de la cuota de los niños de su zona que el centro tuvo antes y con sus plazas como tope; y su alcance en cada
  municipio. Los datos abiertos salen del Instituto Canario de Estadística (ISTAC) cuando el centro los pide (la única
  llamada fuera del sitio, con su fuente citada) o, en cualquier otro sitio, de un CSV del centro.

Datos de ejemplo para una demostración: `php local/oksigeniaeguahe/cli/sample.php`.

## Requisitos

Moodle 4.5 a 5.3. Las páginas públicas no usan el JavaScript de Moodle y funcionan en cualquier móvil reciente.

## Licencia

GNU GPL v3 o posterior. Véase [LICENSE](LICENSE). El widget y la librería de ALTCHA que incluye son MIT
([thirdpartylibs.xml](thirdpartylibs.xml)).

© 2026 Oksigenia SL — dev@oksigenia.cc

La palabra Moodle y los logotipos asociados son marcas comerciales o marcas registradas de Moodle Pty Ltd o de sus
filiales. Oksigenia no está afiliada a Moodle HQ y no es un Moodle Certified Partner.
