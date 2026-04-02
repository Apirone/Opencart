#!/bin/bash
ROOT_PATH=$([[ -n "$1" ]] && echo "$1" || echo $(pwd))
VER=$2

TAG=$([[ -d .git && -n $(git tag --points-at HEAD) ]] && echo $(git tag --points-at HEAD) || echo $(git rev-parse --short HEAD ))
if [[ -n "$3" ]]; then
  TAG=$3
fi

SRC_PATH=${ROOT_PATH}
BUILD_PATH="${ROOT_PATH}/build"
ARC_PATH=${ROOT_PATH}/apirone-crypto-payments.oc${VER}.${TAG:-dev}.ocmod.zip

rm -rf ${BUILD_PATH}

if [[ ${VER} < 4 ]]; then
    DST_PATH="${BUILD_PATH}/upload"
else
    DST_PATH="${BUILD_PATH}"
fi

paths=( $(grep -v '#' ${SRC_PATH}/v${VER}.map) )

src=""
for val in ${paths[@]}
do
    if [[ $src == "" ]]; then
        src=${SRC_PATH}/${val}
    else
        dst=${DST_PATH}/${val}
        mkdir -p `echo ${dst} | sed s/\\\/[^\\\/]*$//`
        cp -R ${src} ${dst}

        src=""
    fi
done

cd "${BUILD_PATH}"
rm -f "${ARC_PATH}"
zip -qr "${ARC_PATH}" ./*
