/**
 * Uploader module for local_questionconverter plugin.
 * @module local_questionconverter/uploader
 * @copyright 2026 Renzo Medina <medinast30@gmail.com>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

export const init = () => {
  const loader = document.getElementById("qc-loader");
  const progressBar = document.getElementById("qc-progress-bar");
  const moodleForm = document.querySelector(".qc-moodle-form-wrapper form");
  const resetButton = moodleForm?.querySelector(
    'input[type="reset"], button[type="reset"], input[name="resetbutton"], button[name="resetbutton"]'
  );
  let progressTimer = null;
  let progressValue = 10;
  let progressDirection = 1;

  const startProgress = () => {
    if (!progressBar || progressTimer) {
      return;
    }
    progressValue = 10;
    progressDirection = 1;
    progressBar.style.transition = "width 0.2s ease";
    progressBar.style.width = progressValue + "%";
    progressTimer = setInterval(function () {
      if (!loader || loader.classList.contains("hidden")) {
        stopProgress();
        return;
      }
      progressValue += progressDirection * 8;
      if (progressValue >= 90) {
        progressValue = 90;
        progressDirection = -1;
      } else if (progressValue <= 10) {
        progressValue = 10;
        progressDirection = 1;
      }
      progressBar.style.width = progressValue + "%";
    }, 200);
  };
  const stopProgress = () => {
    if (progressTimer) {
      clearInterval(progressTimer);
      progressTimer = null;
    }
    if (progressBar) {
      progressBar.style.width = "10%";
    }
  };
  const clearFilepickerUi = () => {
    if (!moodleForm) {
      return;
    }
    const draftInput = moodleForm.querySelector('input[name="pdffile"]');
    if (draftInput) {
      draftInput.value = 0;
    }
    const filepicker = moodleForm.querySelector(".filepicker");
    if (filepicker) {
      const filename = filepicker.querySelector(
        ".fp-filename, .fp-file, .filepicker-filename"
      );
      if (filename) {
        filename.textContent = "";
      }
    }
    const M = window.M || null;
    if (M?.form?.filepicker?.instances) {
      const instances = M.form.filepicker.instances;
      const instance = instances.pdffile ||
        Object.values(instances).find((i) => i?.elementname === "pdffile");
      if (instance && typeof instance.clear === "function") {
        instance.clear();
      }
    }
  };
  if (loader && moodleForm) {
    moodleForm.addEventListener("submit", function () {
      if (
        typeof moodleForm.checkValidity === "function" &&
        !moodleForm.checkValidity()
      ) {
        loader.classList.add("hidden");
        loader.classList.remove("flex");
        stopProgress();
        return;
      }
      loader.classList.remove("hidden");
      loader.classList.add("flex");
      startProgress();
    });
  }
  if (resetButton && moodleForm) {
    resetButton.addEventListener("click", function (event) {
      event.preventDefault();
      moodleForm.reset();
      clearFilepickerUi();
      if (loader) {
        loader.classList.add("hidden");
        loader.classList.remove("flex");
      }
      stopProgress();
    });
  }
};
