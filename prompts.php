<?php
$PROMPT_QA = '{{PERTANYAAN}}';

$SYSTEM_QA = 'Kamu adalah AI Study Helper, asisten belajar cerdas untuk mahasiswa Indonesia.

Kamu akan menjawab pertanyaan berdasarkan materi yang diberikan user.

Aturan:
1. Jawab dengan bahasa Indonesia yang ramah, jelas, dan mudah dipahami mahasiswa
2. Jika ada materi, jawab berdasarkan materi tersebut
3. Jika tidak ada materi atau pertanyaan umum (seperti matematika, logika), jawab dengan pengetahuan umummu
4. Berikan contoh konkret jika membantu pemahaman
5. Format jawaban: langsung ke inti, tidak bertele-tele
6. Jika pertanyaan tidak jelas, minta klarifikasi dengan sopan';

$PROMPT_RINGKASAN = 'Buat ringkasan materi kuliah berikut dalam bahasa Indonesia.

Instruksi:
1. Identifikasi topik utama
2. Buat poin-poin penting (maks 2-3 kalimat per poin)
3. Urutkan dari konsep dasar ke lanjut
4. Tambahkan kata kunci di setiap poin
5. Kesimpulan 1 paragraf di akhir

Format:
## Ringkasan: [Judul Topik]

**Poin 1: [Nama Konsep]**
[Penjelasan]
Kata kunci: [k1], [k2]

---
**Kesimpulan:** [paragraf]

Materi:
{{ISI_PDF}}';

$PROMPT_SOAL = 'Buatkan 10 soal pilihan ganda dari materi berikut.

Instruksi:
- Uji pemahaman konsep, bukan hafalan
- 4 pilihan (A,B,C,D), 1 benar + 3 pengecoh masuk akal
- Tingkat kesulitan: 30% mudah, 50% sedang, 20% sulit
- Penjelasan kenapa jawaban benar

Format JSON:
{
  "soal": [
    {
      "nomor": 1,
      "pertanyaan": "...",
      "pilihan": { "A": "...", "B": "...", "C": "...", "D": "..." },
      "jawaban_benar": "B",
      "penjelasan": "...",
      "tingkat": "mudah|sedang|sulit"
    }
  ]
}

Materi:
{{ISI_PDF}}';

$PROMPT_FLASHCARD = 'Buatkan 15 flashcard dari materi berikut.

Instruksi:
- Fokus definisi, konsep kunci, rumus
- Pertanyaan singkat & spesifik
- Jawaban maks 3 kalimat
- Contoh singkat jika membantu

Format JSON:
{
  "flashcards": [
    {
      "id": 1,
      "depan": "...",
      "belakang": "...",
      "contoh": "...",
      "kategori": "konsep dasar|konsep lanjut|rumus"
    }
  ]
}

Materi:
{{ISI_PDF}}';

$WARNING_MATERIAL = 'PERINGATAN: Materi yang diberikan sangat sedikit (kurang dari 100 karakter). Hasil ringkasan/soal/flashcard mungkin tidak optimal. Disarankan untuk memberikan materi yang lebih lengkap.';
