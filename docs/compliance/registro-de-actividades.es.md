# Registro de actividades de tratamiento: admisiones

Entrada para el registro de actividades del centro, que la revisa y completa su delegado de protección de datos. Los
corchetes los rellena el centro. Corresponde a Oksigenia Eguahe 0.1: sin inteligencia artificial y sin canales de
mensajería, que se añadirán a esta entrada cuando el centro los encienda.

| Campo | Contenido |
|---|---|
| Actividad | Atención a las familias que piden información o plaza, visitas y jornadas de puertas abiertas, y seguimiento de la admisión |
| Responsable | [Nombre legal del centro], [dirección], [correo para ejercer los derechos] |
| Delegado de protección de datos | [Nombre o servicio y su correo, si el centro lo tiene] |
| Encargado del tratamiento | [Quien aloja y mantiene el Moodle del centro], con contrato de encargo |
| Finalidades | Contestar a las familias; organizar visitas y jornadas, con su aforo y su asistencia; seguir cada solicitud hasta la matrícula o su cierre; y conocer, solo con cifras agregadas, cómo va la campaña. Si el centro importa campañas anteriores, sus altas y bajas o su matrícula para analizarlas, entran seudonimizadas y solo para las cifras: el fichero se lee tal cual, al entrar se borran los nombres, las notas, los datos de salud, las contraseñas, las direcciones y los documentos; de la fecha de nacimiento queda solo el año; los correos, los teléfonos, los códigos de alumno y el nombre del niño se convierten en una clave cifrada con un secreto del centro (HMAC-SHA256), que solo sirve para reconocer a la misma familia o al mismo alumno en otro fichero; y el fichero no se guarda |
| Base jurídica | La solicitud de la propia familia (medidas precontractuales) para contestarla y organizar la visita; el consentimiento, para escribirle por cada canal que marque (correo, WhatsApp, teléfono), que puede retirar |
| Interesados | Los adultos de las familias que se dirigen al centro; los menores que estudiarían en él |
| Datos | De los adultos: nombre, apellidos, teléfono, correo, idioma, municipio (si lo dicen), cómo conocieron el centro (si lo dicen), los días y la parte del día que les vienen bien para una visita (si la piden a medida), lo que escriben al centro, las respuestas a las preguntas propias de los formularios del centro (lo que el centro decida preguntar), por dónde llegaron (el formulario, el sitio donde se compartió y, si vienen de un anuncio, su campaña) y los permisos por canal, con su fecha. De los menores: nombre de pila, el nombre que usan en casa (si lo dan) y curso que piden. Eguahe no pide por sí mismo datos de categorías especiales; si un formulario del centro los pregunta, el centro lo anota aquí |
| Decisiones automatizadas | Ninguna. No se puntúa ni se clasifica a ninguna familia |
| Destinatarios | Ninguno fuera del centro, salvo obligación legal. El encargado, por cuenta del centro |
| Transferencias internacionales | Ninguna [si el alojamiento está en la UE] |
| Plazo de supresión | [24] meses sin actividad (ajustable en el centro); la supresión es automática cada noche. Las familias la piden cuando quieren desde su página. Si hay matrícula, los datos pasan a la ficha de familias del centro |
| Medidas de seguridad | Acceso solo para el equipo de admisiones del centro, por usuario y permiso de Moodle; cada centro ve solo sus familias; enlace privado de cada familia guardado solo como huella (SHA-256) y renovable; lo que llega por una herramienta del centro (Google, una hoja, otra herramienta), solo firmado con la clave de esa entrada, y de lo ya borrado queda solo una huella sin la familia, para que reenviarlo no la traiga de vuelta; páginas públicas sin rastreadores, sin terceros y sin indexar; antispam sin terceros (ALTCHA); [copias de seguridad cifradas del sitio, según su alojamiento] |
| Derechos | Desde la página de la familia: descargar sus datos y borrarlos. Por escrito a [correo]: acceso, rectificación, limitación y oposición. Reclamación ante la autoridad de protección de datos |

## Cuándo hay que cambiar esta entrada

Antes de encender en el centro cualquiera de estas piezas, el delegado actualiza esta entrada y la información a las
familias (la primera capa del formulario y la página «Cómo tratamos sus datos»):

- **La inteligencia artificial del equipo** (borradores y resúmenes): añadir como encargado al proveedor de IA que el
  centro configure con su propia clave; las transferencias internacionales, si el proveedor trata los datos fuera del
  Espacio Económico Europeo; y la evaluación de impacto. Lo que se le manda va sin nombres, teléfonos ni correos.
- **WhatsApp o Telegram**: añadir a Meta o a Telegram como destinatarios del mensaje, sus transferencias
  internacionales y el permiso por canal, que la familia da al apuntarse o al escribir.
- **Las entradas** (desde la 0.9.0): los formularios hechos en Eguahe, los de Google, los contactos de los anuncios de
  Meta y cualquier otra herramienta conectada. Añadir cada origen y lo que pide cada formulario. Quien llega por un
  formulario ajeno (Google, Meta) no ha visto la primera capa: el correo de «recibido» y su página se la enseñan, y el
  permiso por canal solo se apunta si ese formulario lo preguntaba; sin él, solo se le contesta a lo que pidió.
  **El buzón de admisiones reenviado** pasa por el servicio de correo de entrada que lo lleva hasta Eguahe (con el
  Worker de `email-worker/`, Cloudflare): añadirlo como encargado del tránsito, que no guarda nada, y sus transferencias
  internacionales. Los adjuntos no entran.
- **Las personas del equipo que reciben a las familias** (desde la 0.6.0): su nombre, su foto, lo que hacen y una
  frase suya salen en las páginas de las familias apuntadas. Es un tratamiento de datos del personal, aparte de este:
  con el permiso de cada persona para usar su imagen, que puede retirar quitándose de «Quién recibe a las familias».
- **Importar lo que ya pasó** (campañas, altas y bajas, listas de alumnos): añadir las fuentes que el centro importe
  (sus formularios, sus anuncios, su secretaría) y el plazo en que guarda las importaciones, que se quitan a mano.
