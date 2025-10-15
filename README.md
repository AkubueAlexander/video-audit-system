# 📚 JAMB CBT Video Audit System

The **JAMB CBT Video Audit System** is a PHP-based web application designed to automatically check and display the availability of video files for JAMB CBT solutions.  
It uses **multi-cURL** for parallel requests, **file-based caching** for faster reloads, and an **interactive UI** to visualize the availability of subject-year videos.

---

## 🚀 Features

✅ **Ultra-Fast Batch Checking:**  
Uses PHP multi-cURL to verify up to 50 video URLs per subject-year simultaneously.

✅ **Smart Caching System:**  
Caches subjects, years, and video availability results for optimized performance and reduced server load.

✅ **Dynamic Data Loading:**  
Automatically fetches available subjects and years from the base URL, falling back to defaults when offline.

✅ **Responsive & Modern UI:**  
Built with pure HTML and CSS — includes collapsible subject lists, progress indicators, and visual audit summaries.

✅ **Lightweight & Dependency-Free:**  
No external libraries or frameworks required — just PHP 7.4+.

---

## 🧠 How It Works

1. **Base URL:**  
   The system fetches all data from:
   https://examkits.com/jamb/cbt/solu/
   
2. **Subjects Discovery:**  
Extracts subject codes dynamically (e.g., ENG, MAT, PHY).

3. **Years Extraction:**  
Fetches all years available per subject (e.g., ENG2019, ENG2020).

4. **Video Audit:**  
For each selected subject and year, it checks 50 possible `.mp4` video links:


2. **Subjects Discovery:**  
Extracts subject codes dynamically (e.g., ENG, MAT, PHY).

3. **Years Extraction:**  
Fetches all years available per subject (e.g., ENG2019, ENG2020).

4. **Video Audit:**  
For each selected subject and year, it checks 50 possible `.mp4` video links:

BASE_URL/subject/year/yearq1.mp4 → yearq50.mp4


5. **Multi-cURL Parallel Requests:**  
Performs all checks simultaneously for maximum speed.

6. **Caching:**  
Stores responses locally in a `/cache` directory for 24 hours.

---

## 🧩 Folder Structure


