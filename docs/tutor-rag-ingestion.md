# US04-A y US04-B

Para configurar y validar US04 desde cero, sigue la [guía de la demo](tutor-demo.md).

US04-A procesa PDF de Materiales desde el CLI. US04-B devuelve evidencia desde `TutorRetriever`, una clase interna que consume la sesión PHP existente. La subida de US01 sigue funcionando independientemente. La ingestión se inicia con el CLI. US04-C usa el recuperador desde un endpoint de prueba autenticado. Consulta [Generación controlada](tutor-rag-generation.md).

En desarrollo, los embeddings se generan mediante Ollama local.
La aplicación mantiene una abstracción de proveedor para permitir reemplazar Ollama posteriormente.

`PdfTextExtractor` implementa `ExtractorInterface`. `FragmentadorTutor` conserva el orden por página y corta a un máximo de 1200 caracteres Unicode. Prefiere un límite de palabra en la segunda mitad del fragmento. Omite trozos compuestos solo por espacios y conserva la numeración física del PDF, incluidas las páginas intermedias sin texto. El PDF original sigue en `materiales.contenido`. El extractor usa archivos temporales de proceso que cierra al terminar. Sin OCR, un PDF escaneado puede quedar `sin_texto`.

## Requisitos y configuración

Usa PostgreSQL con pgvector habilitado en la base objetivo y aplica primero la migración 001. La 004 de esta etapa usa `vector(768)`. Si ya se aplicó el prototipo anterior de 1536 dimensiones, prepara una base de prueba nueva antes de aplicar la 004. Esta migración no convierte vectores anteriores.

La conexión usa `DB_DSN`, `DB_USER` y `DB_PASSWORD` del entorno. PHP requiere PDO PostgreSQL, cURL, mbstring y `proc_open`. Para Docker, prepara un contenedor auxiliar con Poppler y configura su nombre en `TUTOR_PDFTOTEXT_CONTAINER`. Como alternativa configura la ruta de Poppler en `TUTOR_PDFTOTEXT_BIN` o usa `pdftotext` disponible en el PATH del proceso. El extractor verifica su disponibilidad antes de extraer.

Configura en el entorno local:

```text
OLLAMA_BASE_URL=http://127.0.0.1:11434
OLLAMA_EMBEDDING_MODEL=nomic-embed-text
```

El CLI selecciona `OllamaEmbeddingProvider`. Usa la API local de embeddings mediante cURL y comprueba que la respuesta tenga un vector válido de 768 componentes por texto. El servicio de ingestión y el recuperador dependen de `EmbeddingProvider`. No hay claves API. La conexión a Ollama se usa desde el CLI y el endpoint de prueba.

## Ingestión

```text
php cli/tutor_ingestar.php --material=ID --profesor=ID
```

Ejecuta el comando con un operador de confianza que tenga conexión a la base. `--profesor` identifica al docente por cuya asignación se autoriza el trabajo. La consulta comprueba rol, usuario y aula activos, colegio coincidente y asignación al aula del material. Antes de escribir se comprueba otra vez la asignación dentro de la transacción.

`material_ingestas` puede estar en `pendiente`, `procesando`, `procesado`, `sin_texto` o `error`. Materiales aún sin fila esperan su primera ingestión. El CLI registra un intento al comenzar. Si falla la extracción o el proveedor, la fila conserva un motivo y el recuperador excluye el material. Una interrupción del proceso puede dejar la fila en `procesando`. Ejecutar el comando de nuevo permite reintentar tras liberarse el bloqueo de PostgreSQL. Al finalizar se reemplaza el conjunto completo de fragmentos en una transacción, sin duplicados. Borrar un material elimina sus derivados por FK.

```sql
SELECT i.material_id, i.estado, i.intentos, i.error, count(f.id) AS fragmentos,
       min(vector_dims(f.embedding)) AS dimension
FROM material_ingestas i
LEFT JOIN material_fragmentos f ON f.material_id = i.material_id
GROUP BY i.material_id;
```

## Recuperación

`TutorRetriever::buscar` requiere la sesión activa de AulaViva. El cliente solo puede restringir por aula y, opcionalmente, por material. Rol, identidad y colegio salen de la sesión y se verifican con el usuario activo y la matrícula vigentes. La autorización se comprueba antes de solicitar un embedding y se repite en la consulta que selecciona candidatos.

Selecciona hasta cinco candidatos por distancia coseno y cinco por
`ts_rank_cd`. El embedding usa la pregunta original. La búsqueda textual
usa términos en OR, amplía equivalencias de medición e instrucciones en
español e inglés y usa `simple` por defecto. Si se conoce la lengua del
corpus, el consumidor puede usar `english` o `spanish`.

La expansión añade variantes de número gramatical, equivalencias
morfológicas de algunos tecnicismos y traducciones unívocas de lemas o
frases de hasta cuatro palabras. El índice local
`config/tutor/lexico-en-es.tsv.gz` cruza los synsets en español del
[Multilingual Central Repository 3.0](https://adimen.si.ehu.eus/web/MCR/)
(CC BY 3.0, distribuido en Open Multilingual Wordnet 1.4) con los lemas
ingleses de [Princeton WordNet 3.0](https://wordnet.princeton.edu/license-and-commercial-use).
Las dos licencias acompañan al índice. Se puede regenerar con
`python cli/tutor_generar_lexico.py omw-1.4.zip wordnet.zip config/tutor`
usando los [corpus originales empaquetados por NLTK](https://github.com/nltk/nltk_data/tree/gh-pages/packages/corpora).
El índice se lee localmente con zlib de PHP.

Para cada candidato de la unión se suma `1/(60 + posición)` por cada
lista en la que aparece. Antes de escoger tres resultados, se añade
`0,014 × cobertura`, donde la cobertura es la fracción ponderada de
términos normalizados de la pregunta presentes en el candidato. Se
ponderan por dos números, determinadas unidades y los límites
mínimo/máximo. Términos técnicos y nombres cuentan con su coincidencia
léxica ordinaria. El ID del fragmento desempata. El peso máximo de la
cobertura queda por debajo del aporte de cualquiera de las dos listas.

En el ensayo anterior, el pasaje con la profundidad mínima estaba
fuera de ambos rankings. El texto estaba en inglés y la pregunta en
español. La expansión léxica aborda esa diferencia antes de fusionar
resultados. Sigue siendo una búsqueda exacta sobre candidatos
autorizados y el vocabulario tiene cobertura limitada. El filtro de
evidencia decide si se puede generar una respuesta.
