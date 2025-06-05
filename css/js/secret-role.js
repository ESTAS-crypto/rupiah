// Particles.js initialization
particlesJS('particles-js', {
    "particles": {
        "number": {
            "value": 80,
            "density": {
                "enable": true,
                "value_area": 800
            }
        },
        "color": {
            "value": "#673ab7"
        },
        "shape": {
            "type": "circle",
            "stroke": {
                "width": 0,
                "color": "#000000"
            },
            "polygon": {
                "nb_sides": 5
            }
        },
        "opacity": {
            "value": 0.5,
            "random": false,
            "anim": {
                "enable": false,
                "speed": 1,
                "opacity_min": 0.1,
                "sync": false
            }
        },
        "size": {
            "value": 3,
            "random": true,
            "anim": {
                "enable": false,
                "speed": 40,
                "size_min": 0.1,
                "sync": false
            }
        },
        "line_linked": {
            "enable": true,
            "distance": 150,
            "color": "#673ab7",
            "opacity": 0.4,
            "width": 1
        },
        "move": {
            "enable": true,
            "speed": 2,
            "direction": "none",
            "random": false,
            "straight": false,
            "out_mode": "out",
            "bounce": false,
            "attract": {
                "enable": false,
                "rotateX": 600,
                "rotateY": 1200
            }
        }
    },
    "interactivity": {
        "detect_on": "canvas",
        "events": {
            "onhover": {
                "enable": true,
                "mode": "repulse"
            },
            "onclick": {
                "enable": true,
                "mode": "push"
            },
            "resize": true
        },
        "modes": {
            "grab": {
                "distance": 140,
                "line_linked": {
                    "opacity": 1
                }
            },
            "bubble": {
                "distance": 400,
                "size": 40,
                "duration": 2,
                "opacity": 8,
                "speed": 3
            },
            "repulse": {
                "distance": 100,
                "duration": 0.4
            },
            "push": {
                "particles_nb": 4
            },
            "remove": {
                "particles_nb": 2
            }
        }
    },
    "retina_detect": true
});

// Cursor trail effect
document.addEventListener('DOMContentLoaded', function() {
    const body = document.querySelector('body');
    let mouseX = 0,
        mouseY = 0;
    let trailElements = [];
    const trailLength = 20;

    for (let i = 0; i < trailLength; i++) {
        const trail = document.createElement('div');
        trail.className = 'trail';
        trail.style.opacity = (1 - i / trailLength) * 0.7;
        document.body.appendChild(trail);
        trailElements.push({ element: trail, x: 0, y: 0 });
    }

    window.addEventListener('mousemove', function(e) {
        mouseX = e.clientX;
        mouseY = e.clientY;
    });

    function updateTrail() {
        trailElements.forEach((trail, index) => {
            const nextTrail = trailElements[index - 1] || { x: mouseX, y: mouseY };
            trail.x += (nextTrail.x - trail.x) * 0.3;
            trail.y += (nextTrail.y - trail.y) * 0.3;
            trail.element.style.left = trail.x + 'px';
            trail.element.style.top = trail.y + 'px';
        });
        requestAnimationFrame(updateTrail);
    }

    updateTrail();
});

// Matrix rain effect
let konamiCode = ['ArrowUp', 'ArrowUp', 'ArrowDown', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'ArrowLeft', 'ArrowRight', 'b', 'a'];
let konamiPosition = 0;

document.addEventListener('keydown', function(e) {
    if (e.key === konamiCode[konamiPosition]) {
        konamiPosition++;
        if (konamiPosition === konamiCode.length) {
            konamiPosition = 0;
            activateMatrixRain();
        }
    } else {
        konamiPosition = 0;
    }
});

function activateMatrixRain() {
    alert('Kode Rahasia Diaktifkan: Matrix Mode!');

    const canvas = document.createElement('canvas');
    canvas.width = window.innerWidth;
    canvas.height = window.innerHeight;
    canvas.style.position = 'fixed';
    canvas.style.top = '0';
    canvas.style.left = '0';
    canvas.style.zIndex = '-1';
    canvas.style.opacity = '0.8';
    document.body.appendChild(canvas);

    const ctx = canvas.getContext('2d');
    const matrix = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ123456789@#$%^&*()*&^%+-/~{[|`]}}";
    const columns = canvas.width / 20;
    const drops = Array(Math.floor(columns)).fill(1);

    function draw() {
        ctx.fillStyle = "rgba(0, 0, 0, 0.04)";
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = "#673ab7";
        ctx.font = "15px monospace";

        drops.forEach((y, i) => {
            const text = matrix[Math.floor(Math.random() * matrix.length)];
            ctx.fillText(text, i * 20, y * 20);
            if (y * 20 > canvas.height && Math.random() > 0.975) {
                drops[i] = 0;
            }
            drops[i]++;
        });
    }

    const interval = setInterval(draw, 35);
    setTimeout(() => {
        clearInterval(interval);
        canvas.remove();
    }, 20000);
}