from flask import Flask, request, jsonify
from deepface import DeepFace
import base64
import os
import cv2
import numpy as np

app = Flask(__name__)

# --- KONFIGURASI ---
DB_PATH = "dataset_wajah"    
MODEL_NAME = "VGG-Face"      
METRIC = "cosine"            

# SAYA NAIKKAN JADI 0.65 AGAR LEBIH MUDAH TERDETEKSI
# (Default VGG-Face biasanya 0.40. Angka 0.65 itu sudah sangat toleran)
CUSTOM_THRESHOLD = 0.55      

# Pastikan folder database ada
if not os.path.exists(DB_PATH):
    os.makedirs(DB_PATH)

print("Meload model DeepFace... Harap tunggu...")
DeepFace.build_model(MODEL_NAME)
print(f"Model siap! Menggunakan Threshold Longgar: {CUSTOM_THRESHOLD}")
print("Server berjalan di port 5000")

@app.route('/recognize', methods=['POST'])
def recognize():
    try:
        data = request.json
        image_data = data.get('image')

        if not image_data:
            return jsonify({'status': 'error', 'message': 'No image data'}), 400

        # Decode Base64 ke Image
        if "," in image_data:
            image_data = image_data.split(",")[1]
            
        img_bytes = base64.b64decode(image_data)
        nparr = np.frombuffer(img_bytes, np.uint8)
        img = cv2.imdecode(nparr, cv2.IMREAD_COLOR)

        # Simpan sementara
        temp_filename = "temp_scan.jpg"
        cv2.imwrite(temp_filename, img)

        print("\n--- Memproses Wajah ---")
        
        # Proses Pencarian
        try:
            results = DeepFace.find(img_path=temp_filename, 
                                  db_path=DB_PATH, 
                                  model_name=MODEL_NAME,
                                  distance_metric=METRIC,
                                  enforce_detection=False, # False = Tetap proses meski wajah miring/buram
                                  silent=True,
                                  threshold=CUSTOM_THRESHOLD)
        except Exception as e:
            # Kadang error jika wajah benar2 tidak ditemukan di frame
            print(f"DeepFace Warning: {e}")
            return jsonify({'status': 'error', 'message': 'Wajah tidak ditemukan di kamera'})

        # Cek hasil
        if len(results) > 0 and not results[0].empty:
            match = results[0].iloc[0] # Ambil hasil paling mirip
            match_path = match['identity']
            
            # Kolom distance kadang namanya beda tergantung versi pandas/deepface, kita ambil aman
            # Biasanya namanya "VGG-Face_cosine"
            col_name = f"{MODEL_NAME}_{METRIC}"
            if col_name in match:
                distance = match[col_name]
            else:
                # Ambil kolom terakhir sebagai fallback jika nama kolom beda
                distance = match.iloc[-1]

            print(f"LOG: Ditemukan {os.path.basename(match_path)} | Jarak: {distance:.4f} (Batas: {CUSTOM_THRESHOLD})")

            # Ambil NIS dari nama file
            filename = os.path.basename(match_path)
            nis = os.path.splitext(filename)[0]
            
            return jsonify({
                'status': 'success', 
                'nis': nis,
                'distance': float(distance)
            })
        else:
            print(f"LOG: Gagal. Tidak ada wajah yang kemiripannya di bawah {CUSTOM_THRESHOLD}")
            return jsonify({'status': 'error', 'message': 'Wajah tidak dikenali'})

    except Exception as e:
        print(f"ERROR SYSTEM: {e}")
        return jsonify({'status': 'error', 'message': str(e)}), 500

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5000)