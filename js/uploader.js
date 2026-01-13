// Envolver en función anónima para evitar conflictos
(function() {
    'use strict';
    
    function initUploader() {
    const fileInput = document.getElementById('document-file')
    const btnConvert = document.getElementById('btn-convert')
    const btnReset = document.getElementById('btn-reset')
    const btnChange = document.getElementById('btn-change')
    const dropZone = document.getElementById('drop-zone')
    const uploadContent = document.getElementById('upload-content')
    const fileSelectedContent = document.getElementById('file-selected-content')
    const fileName = document.getElementById('file-name')
    const fileSize = document.getElementById('file-size')

    
    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes'
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB']
        const i = Math.floor(Math.log(bytes) / Math.log(k))
        return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i]
    }

    function handleFileSelect() {
        if (fileInput.files && fileInput.files[0]) {
            const file = fileInput.files[0]
            
            if (file.type === 'application/pdf') {
                
                fileName.textContent = file.name
                fileSize.textContent = formatFileSize(file.size)
            
                
                uploadContent.classList.add('hidden')
                fileSelectedContent.classList.remove('hidden')
                fileSelectedContent.classList.add('flex')
                
                
                dropZone.classList.remove('border-gray-300', 'hover:border-primary', 'hover:bg-blue-50')
                dropZone.classList.add('border-green-500', 'bg-green-50')
                
                
                btnConvert.classList.remove('hidden')
                btnReset.classList.remove('hidden')
            } else {
                alert('Por favor selecciona un archivo PDF')
                fileInput.value = ''
                resetView()
            }
        }
    }

    
    function resetView() {
        uploadContent.classList.remove('hidden')
        fileSelectedContent.classList.add('hidden')
        fileSelectedContent.classList.remove('flex')
        dropZone.classList.remove('border-green-500', 'bg-green-50')
        dropZone.classList.add('border-gray-300', 'hover:border-primary', 'hover:bg-blue-50')
        btnConvert.classList.add('hidden')
        btnReset.classList.add('hidden')
        fileInput.value = ''
    }

    
    fileInput.addEventListener('change', handleFileSelect)

    
    btnChange.addEventListener('click', (e) => {
        e.stopPropagation()
        resetView()
        fileInput.click()
    })

    btnReset.addEventListener('click', (e) => {
        e.stopPropagation()
        resetView()
    })
    
    let dragCounter = 0

    dropZone.addEventListener('dragenter', (e) => {
        e.preventDefault()
        e.stopPropagation()
        dragCounter++
        dropZone.classList.add('border-primary', 'bg-blue-100', 'scale-105')
        dropZone.classList.remove('border-gray-300', 'border-green-500')
    });

    dropZone.addEventListener('dragleave', (e) => {
        e.preventDefault()
        e.stopPropagation()
        dragCounter--
        if (dragCounter === 0) {
            dropZone.classList.remove('border-primary', 'bg-blue-100', 'scale-105')
            if (fileInput.files && fileInput.files[0]) {
                dropZone.classList.add('border-green-500', 'bg-green-50')
            } else {
                dropZone.classList.add('border-gray-300')
            }
        }
    });

    dropZone.addEventListener('dragover', (e) => {
        e.preventDefault()
        e.stopPropagation()
    });

    dropZone.addEventListener('drop', (e) => {
        e.preventDefault()
        e.stopPropagation()
        dragCounter = 0
    
        dropZone.classList.remove('border-primary', 'bg-blue-100', 'scale-105')
        
        if (e.dataTransfer.files.length) {
            fileInput.files = e.dataTransfer.files
            handleFileSelect()
        }
    });

    document.getElementById('document-file').addEventListener('change', function() {
        const fileName = this.files[0] ? this.files[0].name : 'Ningún archivo seleccionado'
        document.querySelector('.file-button').textContent = fileName
    })

    setTimeout(() => {
        window.history.replaceState({}, document.title, window.location.pathname)
    }, 7000);

    const loader = document.getElementById("loader")
    document.getElementById('formulario').addEventListener('submit', function(e) {
        loader.classList.remove('hidden')
        loader.classList.add('flex')
    });
    
}

// Inicializar cuando el DOM esté listo
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initUploader);
} else {
    initUploader();
}
})();