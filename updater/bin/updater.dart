import 'dart:io';
import 'package:archive/archive_io.dart';
import 'package:path/path.dart' as p;

void main(List<String> args) async {
  String? component;
  String? targetDir;
  String? zipPath;

  // Parser básico de argumentos
  for (final arg in args) {
    if (arg.startsWith('--component=')) {
      component = arg.split('=')[1];
    } else if (arg.startsWith('--target-dir=')) {
      targetDir = arg.substring('--target-dir='.length).replaceAll('"', '');
    } else if (arg.startsWith('--zip-path=')) {
      zipPath = arg.substring('--zip-path='.length).replaceAll('"', '');
    }
  }

  // Fallback (versiones antiguas)
  if (component == null && args.length >= 2 && !args[0].startsWith('--')) {
    component = 'frontend';
    zipPath = args[0].replaceAll('"', '');
    targetDir = args[1].replaceAll('"', '');
  }

  if (component == null || targetDir == null || zipPath == null) {
    print('Uso: updater.exe --component=<frontend/backend> --target-dir=<ruta> --zip-path=<ruta>');
    exit(1);
  }

  print('Updater Iniciado.');
  print('Componente: $component');
  print('Ruta ZIP: $zipPath');
  print('Ruta Destino: $targetDir');

  print('Esperando 3 segundos a que los procesos liberen los archivos...');
  await Future.delayed(const Duration(seconds: 3));

  print('Extrayendo actualizaciones en el directorio...');
  try {
    final bytes = File(zipPath).readAsBytesSync();
    final archive = ZipDecoder().decodeBytes(bytes);

    for (final file in archive) {
      final filename = file.name;
      if (file.isFile) {
        final data = file.content as List<int>;
        final filePath = p.join(targetDir, filename);
        final outFile = File(filePath);
        outFile.createSync(recursive: true);
        outFile.writeAsBytesSync(data);
      } else {
        final dirPath = p.join(targetDir, filename);
        Directory(dirPath).createSync(recursive: true);
      }
    }
    print('Extracción completada con éxito.');
  } catch (e) {
    print('Error extrayendo el ZIP: $e');
  }

  // Lógicas por componente
  if (component == 'backend') {
    print('Ejecutando php artisan migrate --force en backend...');
    try {
      final result = Process.runSync(
        'php',
        ['artisan', 'migrate', '--force'],
        workingDirectory: targetDir,
      );
      print('Migración stdout:\n${result.stdout}');
      if (result.stderr.toString().trim().isNotEmpty) {
        print('Migración stderr:\n${result.stderr}');
      }
    } catch (e) {
      print('Error al intentar ejecutar la migración: $e');
    }

    try {
      File(zipPath).deleteSync();
      print('Archivo ZIP $zipPath eliminado correctamente.');
    } catch (e) {
      print('No se pudo eliminar el ZIP temporal: $e');
    }
    
  } else if (component == 'frontend') {
    final exePath = p.join(targetDir, 'Sistema_POS.exe');
    if (File(exePath).existsSync()) {
      print('Lanzando la aplicación actualizada...');
      try {
        await Process.start(exePath, [], workingDirectory: targetDir, mode: ProcessStartMode.detached);
      } catch (e) {
        print('Error al abrir $exePath: $e');
      }
    } else {
      print('Advertencia: No se encontró $exePath para reabrir automáticamente.');
    }
  }

  print('Proceso de actualización finalizado.');
  exit(0);
}
